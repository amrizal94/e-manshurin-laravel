"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { useParams, useRouter } from "next/navigation";
import { api } from "@/lib/api";
import { TombolKembali } from "@/components/TombolKembali";
import { useKonfirmasi } from "@/components/Konfirmasi";
import { useKamera } from "@/lib/useKamera";
import { useRoleGuard } from "@/lib/useRoleGuard";

const API_ORIGIN = (process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api").replace(/\/api$/, "");

/**
 * Sisi terpanjang saat dikirim. Bukan 640 seperti jepretan kamera: di foto galeri
 * wajahnya sering jauh dan kecil, dan kalau dikecilkan terlalu agresif wajahnya
 * malah tidak terdeteksi sama sekali. 1280 masih ratusan kilobyte.
 */
const MAKS_SISI = 1280;

/** Batas kirim backend. Di atas ini tidak ada gunanya dicoba. */
const MAKS_KIRIM = 15 * 1024 * 1024;
/**
 * Ditulis sebelum pemilih galeri dibuka, dihapus begitu berkasnya sampai ke halaman.
 * Kalau masih ada saat halaman dimuat, berarti halamannya mati di antara keduanya --
 * dan itu tidak meninggalkan jejak lain: tidak ada galat, tidak ada permintaan ke
 * server, cuma halaman kosong yang terbaca sebagai "uploadnya rusak".
 */
const TANDA_GALERI = "wajah-galeri-dibuka";

/**
 * Pengecilan foto, dijalankan di pekerja terpisah.
 *
 * Membuka foto 50 MP perlu ratusan megabyte, dan di HP itu bisa mematikan seluruh
 * halaman — layar hitam, alamat kosong, tidak ada pesan apa pun, dan tidak ada satu pun
 * permintaan yang terkirim. Pekerja terpisah memindahkan kerja beratnya dari utas utama,
 * jadi tombol dan tulisannya tetap hidup selama gambar dibongkar.
 *
 * Yang TIDAK dilakukannya: melindungi halaman dari kehabisan memori. Dedicated worker
 * berbagi proses renderer dengan halamannya di Chrome, jadi pekerja yang kehabisan memori
 * tetap membunuh tabnya. Jangan andalkan ini sebagai pengaman.
 *
 * Ditulis sebagai teks lalu dijalankan dari Blob, bukan berkas tersendiri: tidak perlu
 * menyentuh setelan pembangun proyek demi dua puluh baris.
 */
const KODE_PEKERJA = `
self.onmessage = async (e) => {
  const { file, maksSisi } = e.data;
  try {
    // resizeWidth menyuruh browser membaca langsung pada ukuran kecil. Sebagian browser
    // mengabaikannya dan tetap membuka utuh — itu pun aman di sini, karena yang kehabisan
    // memori cuma pekerja ini.
    const besar = file.size > 1048576;
    const bitmap = await createImageBitmap(file, besar ? { resizeWidth: maksSisi, resizeQuality: 'high' } : undefined);
    const skala = Math.min(1, maksSisi / Math.max(bitmap.width, bitmap.height));
    const lebar = Math.round(bitmap.width * skala);
    const tinggi = Math.round(bitmap.height * skala);
    const kanvas = new OffscreenCanvas(lebar, tinggi);
    kanvas.getContext('2d').drawImage(bitmap, 0, 0, lebar, tinggi);
    bitmap.close();
    const blob = await kanvas.convertToBlob({ type: 'image/jpeg', quality: 0.9 });
    self.postMessage({ ok: true, blob });
  } catch (err) {
    self.postMessage({ ok: false });
  }
};
`;

/**
 * Hasilnya null kalau gagal — browser lama tanpa OffscreenCanvas, pekerja yang mati
 * kehabisan memori, atau format yang tidak terbaca. Pemanggilnya mengirim foto aslinya,
 * dan server yang mengecilkan.
 */
function kecilkanDiPekerja(file: File, batasDetik = 25): Promise<File | null> {
  return new Promise((selesai) => {
    let alamat = "";
    let pekerja: Worker;

    try {
      alamat = URL.createObjectURL(new Blob([KODE_PEKERJA], { type: "text/javascript" }));
      pekerja = new Worker(alamat);
    } catch {
      if (alamat) URL.revokeObjectURL(alamat);

      return selesai(null);
    }

    const tutup = (hasil: File | null) => {
      clearTimeout(jam);
      pekerja.terminate();
      URL.revokeObjectURL(alamat);
      selesai(hasil);
    };

    // Pembacaan gambar di HP bisa menggantung tanpa pernah gagal. Tanpa batas ini tombolnya
    // diam di "Memproses..." selamanya dan petugas tidak pernah tahu apa yang terjadi.
    const jam = setTimeout(() => tutup(null), batasDetik * 1000);

    pekerja.onmessage = (e: MessageEvent<{ ok: boolean; blob?: Blob }>) =>
      tutup(e.data?.ok && e.data.blob
        ? new File([e.data.blob], file.name.replace(/\.[^.]+$/, "") + ".jpg", { type: "image/jpeg" })
        : null);
    pekerja.onerror = () => tutup(null);

    pekerja.postMessage({ file, maksSisi: MAKS_SISI });
  });
}

interface Foto { id: number; path: string }
/** `gambar` cuma diisi untuk yang gagal — alamat sementara di browser, bukan di server. */
interface Hasil { nama: string; pesan: string; ok: boolean; gambar?: string }
interface Jamaah { id: number; nama_lengkap: string; photos: Foto[] }

export default function WajahPage() {
  useRoleGuard(["super_admin", "admin"]);
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
  const tanya = useKonfirmasi();
  const [jamaah, setJamaah] = useState<Jamaah | null>(null);
  // Hasil per foto, bukan satu pesan. Dengan tiga foto sekaligus, satu baris pesan
  // selalu berbohong tentang dua foto lainnya.
  const [hasil, setHasil] = useState<Hasil[]>([]);
  const [error, setError] = useState("");
  const [uploading, setUploading] = useState(false);
  // Tiap foto menunggu face-service (timeout 15 detik di backend). Tanpa angka,
  // tombol diam belasan detik dan petugas mengira halamannya menggantung.
  const [antre, setAntre] = useState<{ ke: number; dari: number } | null>(null);
  const [loading, setLoading] = useState(true);
  const [kamera, setKamera] = useState(false);
  const [galeriTerputus, setGaleriTerputus] = useState(false);
  const videoRef = useRef<HTMLVideoElement>(null);
  const {
    daftar: daftarKamera,
    dipilih: kameraDipilih,
    pilih: pilihKamera,
    siap,
    error: errorKamera,
  } = useKamera(videoRef, kamera);

  const reload = useCallback(() => {
    api<Jamaah>(`/jamaahs/${id}`)
      .then((r) => setJamaah(r.data))
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [id]);

  useEffect(reload, [reload]);

  useEffect(() => {
    // Ditunda satu putaran, sama seperti pembacaan alamat di halaman Data Jamaah:
    // sessionStorage tidak ada saat render di server, jadi tidak bisa dibaca lebih awal.
    Promise.resolve().then(() => {
      try {
        if (sessionStorage.getItem(TANDA_GALERI)) {
          sessionStorage.removeItem(TANDA_GALERI);
          setGaleriTerputus(true);
        }
      } catch {
        // penyimpanan diblokir -- penjelasannya hilang, sisanya tetap jalan
      }
    });
  }, []);

  /**
   * Keluar di tengah pengiriman membuang foto yang belum terkirim tanpa jejak. Dua jalur
   * keluar yang berbeda, jadi dua penjaga: menutup atau memuat ulang halaman ditangkap
   * browser, sedangkan pindah lewat menu di dalam aplikasi tidak pernah memuat ulang
   * halaman — jadi klik tautannya sendiri yang dicegat.
   */
  useEffect(() => {
    if (!uploading) return;

    const cegahTutup = (e: BeforeUnloadEvent) => e.preventDefault();
    const cegat = (e: MouseEvent) => {
      const tautan = (e.target as HTMLElement | null)?.closest?.("a");
      const tujuan = tautan?.getAttribute("href");

      if (!tujuan) return;

      // Dihentikan dulu: menunggu jawaban tidak bisa dilakukan di tengah penanganan klik.
      // Kalau diizinkan, halamannya yang memindahkan sendiri.
      e.preventDefault();
      e.stopPropagation();

      tanya({
        pesan: "Masih ada foto yang sedang dikirim.",
        keterangan: "Foto yang belum terkirim akan hilang kalau halaman ini ditinggalkan sekarang.",
        tombol: "Tinggalkan",
      }).then((ya) => ya && router.push(tujuan));
    };

    window.addEventListener("beforeunload", cegahTutup);
    document.addEventListener("click", cegat, true);

    return () => {
      window.removeEventListener("beforeunload", cegahTutup);
      document.removeEventListener("click", cegat, true);
    };
  }, [uploading, router, tanya]);

  /** Alamat sementara itu menahan berkasnya di memori sampai dilepas — 10 MB per foto. */
  function tutupHasil() {
    setHasil((lama) => {
      lama.forEach((h) => h.gambar && URL.revokeObjectURL(h.gambar));

      return [];
    });
  }

  function unggah(e: React.ChangeEvent<HTMLInputElement>) {
    const files = Array.from(e.target.files ?? []);
    e.target.value = "";
    // Sampai di sini berarti halamannya selamat dari pemilih galeri.
    try {
      sessionStorage.removeItem(TANDA_GALERI);
    } catch {
      // diabaikan, sama seperti saat menulisnya
    }
    if (files.length) kirim(files);
  }

  /** Jepret dari kamera. Kamera dibiarkan menyala: butuh minimal 3 foto, jadi
   *  petugas bisa langsung ambil beberapa kali dengan pose berbeda. */
  async function jepret() {
    const video = videoRef.current;
    if (!video) return;
    const canvas = document.createElement("canvas");
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext("2d")!.drawImage(video, 0, 0);
    const blob = await new Promise<Blob | null>((r) => canvas.toBlob(r, "image/jpeg", 0.9));
    if (blob) kirim([new File([blob], "wajah.jpg", { type: "image/jpeg" })]);
  }

  /**
   * Satu per satu: backend menerima satu foto per request. Hasilnya dicatat per foto —
   * wajah tak terdeteksi di satu foto itu biasa, dan petugas perlu tahu foto mana.
   * Pengecilan ikut masuk ke dalam try: justru kegagalan sebelum terkirim yang selama
   * ini hilang tanpa jejak, dan itu yang terbaca sebagai "tidak bisa upload".
   */
  async function kirim(fotos: File[]) {
    setError("");
    tutupHasil();
    setUploading(true);

    const catatan: Hasil[] = [];

    for (const [i, asli] of fotos.entries()) {
      setAntre({ ke: i + 1, dari: fotos.length });
      try {
        // Gagal dikecilkan bukan alasan berhenti: server menerima sampai 15 MB dan
        // mengecilkannya sendiri. Yang penting halaman ini tidak ikut membuka gambarnya.
        const foto = (await kecilkanDiPekerja(asli)) ?? asli;

        if (foto.size > MAKS_KIRIM) {
          throw new Error(
            `ukurannya ${(foto.size / 1024 / 1024).toFixed(1)} MB, melebihi batas 15 MB. Pakai tombol "Pakai Kamera" untuk memotret langsung.`
          );
        }

        const body = new FormData();
        body.append("photo", foto, foto.name);
        const res = await api(`/jamaahs/${id}/face-enroll`, { method: "POST", body });
        catatan.push({ nama: asli.name, pesan: res.message, ok: true });
      } catch (err) {
        catatan.push({
          nama: asli.name,
          pesan: err instanceof Error ? err.message : "gagal unggah",
          ok: false,
          // Yang gagal ditampilkan gambarnya: mencocokkan "20260928_193333.jpg" dengan
          // foto di galeri itu pekerjaan sendiri, apalagi kalau tiga-tiganya beruntun.
          gambar: URL.createObjectURL(asli),
        });
      }
      setHasil([...catatan]);
    }

    setAntre(null);
    setUploading(false);
    reload();
  }

  async function hapus(foto: Foto) {
    if (!(await tanya({ pesan: "Hapus foto ini?", keterangan: "Data wajah dari foto ini ikut terhapus, dan jamaahnya bisa jadi kurang dari 3 foto.", bahaya: true }))) return;
    try {
      await api(`/jamaahs/${id}/photos/${foto.id}`, { method: "DELETE" });
      reload();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Gagal menghapus");
    }
  }

  const total = jamaah?.photos.length ?? 0;

  return (
    <div className="space-y-4">
      <TombolKembali href="/jamaah">← Kembali ke Data Jamaah</TombolKembali>
      <h2 className="text-xl font-bold text-gray-900">
        Foto Wajah — {jamaah?.nama_lengkap ?? "..."}
      </h2>
      <p className="text-sm text-gray-500">
        {total} foto tersimpan. Minimal 3 foto untuk face recognition
        {total < 3 && ` (kurang ${3 - total})`}.
      </p>

      {galeriTerputus && (
        <p className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
          <strong>Halaman ini sempat ditutup HP waktu memilih foto</strong>, jadi fotonya
          tidak jadi terkirim. Itu bukan kerusakan aplikasi: galeri yang penuh foto besar
          membuat Android membuang halaman yang sedang di belakang. Pakai{" "}
          <strong>Pakai Kamera</strong> — memotret di sini tidak pernah meninggalkan halaman.
        </p>
      )}

      {/* Memilih dari galeri membuat HP meninggalkan halaman ini, dan foto 10 MB-an bikin
          sistem membuang halamannya diam-diam. Kamera dalam halaman tidak punya risiko itu. */}
      <p className="rounded-lg bg-gray-50 p-2 text-xs text-gray-600">
        Paling aman pakai <strong>Pakai Kamera</strong> — memotret langsung di sini, tanpa
        meninggalkan halaman. Galeri tetap bisa, tapi foto 10 MB-an kadang membuat HP
        menutup halaman ini sendiri sebelum fotonya sempat terkirim.
      </p>

      {/* Tulisan kecil di tombol tidak cukup: petugas memotret sambil berdiri, dan tiga
          foto bisa makan satu menit karena tiap foto menunggu face-service. */}
      {uploading && (
        <div className="rounded-xl border border-emerald-300 bg-emerald-50 p-3">
          <p className="text-sm font-semibold text-emerald-900">
            Mengirim foto {antre?.ke ?? 1} dari {antre?.dari ?? 1}...
          </p>
          <div className="mt-2 h-2 overflow-hidden rounded bg-emerald-100">
            <div
              className="h-full animate-pulse bg-emerald-600 transition-all duration-300"
              style={{ width: `${(((antre?.ke ?? 1) - 1) / (antre?.dari ?? 1)) * 100}%`, minWidth: 8 }}
            />
          </div>
          <p className="mt-1 text-xs text-emerald-800">Jangan tutup atau pindah halaman dulu.</p>
        </div>
      )}

      {error && <p className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}

      {/* Tidak hilang sendiri: petugas sering baru melihat layar sesudah foto terakhir,
          dan kalau pesannya sudah lewat, yang tersisa cuma kesan "tidak terjadi apa-apa". */}
      {hasil.length > 0 && (
        <div className="rounded-xl border border-gray-200 bg-white p-3">
          <div className="flex items-start justify-between gap-3">
            <p className="text-sm font-semibold text-gray-900">
              {hasil.filter((h) => h.ok).length} foto tersimpan
              {hasil.some((h) => !h.ok) && ` · ${hasil.filter((h) => !h.ok).length} gagal`}
            </p>
            <button
              onClick={tutupHasil}
              aria-label="Tutup hasil unggah"
              className="shrink-0 rounded px-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600"
            >
              ✕
            </button>
          </div>
          <ul className="mt-2 space-y-1">
            {hasil.map((h, i) => (
              <li key={i} className={`flex items-start gap-2 text-sm ${h.ok ? "text-gray-600" : "text-red-700"}`}>
                {h.gambar ? (
                  // eslint-disable-next-line @next/next/no-img-element
                  <img src={h.gambar} alt="" className="h-12 w-12 shrink-0 rounded border border-red-200 object-cover" />
                ) : (
                  <span aria-hidden="true">{h.ok ? "✓" : "✕"}</span>
                )}
                <span className="min-w-0 break-words">
                  <span className="font-medium">{h.nama}</span> — {h.pesan}
                </span>
              </li>
            ))}
          </ul>
        </div>
      )}

      <div className="flex flex-wrap items-center gap-3">
        {/* Kamera didahulukan, dan itu bukan soal selera: memotret di sini tidak pernah
            meninggalkan halaman, sedangkan pemilih galeri memindahkan Chrome ke latar.
            Pada 29 Oktober 2026 Android membunuh tabnya di sana -- tiga foto 11 MB
            tercentang, tab hilang, nol permintaan sampai ke server. */}
        <button
          onClick={() => setKamera((k) => !k)}
          disabled={uploading}
          className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-60"
        >
          {kamera ? "Tutup Kamera" : "Pakai Kamera"}
        </button>

        <label className="inline-block cursor-pointer rounded-lg border border-emerald-600 px-4 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-50">
          {uploading
            ? `Memproses${antre && antre.dari > 1 ? ` ${antre.ke}/${antre.dari}` : ""}...`
            : "Pilih dari Galeri"}
          <input
            type="file"
            accept="image/*"
            multiple
            className="hidden"
            disabled={uploading}
            onChange={unggah}
            onClick={() => {
              try {
                sessionStorage.setItem(TANDA_GALERI, String(Date.now()));
              } catch {
                // mode penyamaran atau penyimpanan diblokir -- cuma kehilangan penjelasannya
              }
            }}
          />
        </label>
      </div>

      {kamera && (
        <div className="space-y-3">
          {errorKamera && <p className="rounded bg-red-50 p-2 text-sm text-red-700">{errorKamera}</p>}

          <video
            ref={videoRef}
            autoPlay
            playsInline
            muted
            className="aspect-[4/3] w-full max-w-md rounded-xl border border-gray-200 bg-black object-cover"
          />

          <div className="flex flex-wrap items-center gap-3">
            <button
              onClick={jepret}
              disabled={!siap || uploading}
              className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
            >
              {uploading ? "Memproses..." : "Jepret"}
            </button>

            {/* Cuma muncul kalau perangkatnya memang punya lebih dari satu kamera. */}
            {daftarKamera.length > 1 && (
              <label className="flex items-center gap-2 text-sm text-gray-500">
                Kamera
                <select
                  value={kameraDipilih}
                  onChange={(e) => pilihKamera(e.target.value)}
                  className="rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700"
                >
                  <option value="">Kamera bawaan</option>
                  {daftarKamera.map((d, i) => (
                    <option key={d.deviceId} value={d.deviceId}>
                      {d.label || `Kamera ${i + 1}`}
                    </option>
                  ))}
                </select>
              </label>
            )}
          </div>
        </div>
      )}

      {loading && <p className="text-sm text-gray-400">Memuat...</p>}

      <div className="grid grid-cols-2 gap-4 sm:grid-cols-4 lg:grid-cols-6">
        {jamaah?.photos.map((f) => (
          <div key={f.id} className="relative overflow-hidden rounded-xl border border-gray-200">
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img
              src={`${API_ORIGIN}/storage/${f.path}`}
              alt="Foto wajah"
              className="aspect-square w-full object-cover"
            />
            <button
              onClick={() => hapus(f)}
              className="absolute right-1 top-1 rounded bg-red-600/90 px-2 py-1 text-xs font-medium text-white hover:bg-red-600"
            >
              Hapus
            </button>
          </div>
        ))}
      </div>
    </div>
  );
}
