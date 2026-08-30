"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { useParams } from "next/navigation";
import Link from "next/link";
import { api } from "@/lib/api";
import { useKamera } from "@/lib/useKamera";
import { useRoleGuard } from "@/lib/useRoleGuard";

const API_ORIGIN = (process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api").replace(/\/api$/, "");

/**
 * Sisi terpanjang saat dikirim. Bukan 640 seperti jepretan kamera: di foto galeri
 * wajahnya sering jauh dan kecil, dan kalau dikecilkan terlalu agresif wajahnya
 * malah tidak terdeteksi sama sekali. 1280 masih ratusan kilobyte.
 */
const MAKS_SISI = 1280;

/**
 * Kecilkan di browser sebelum kirim. Foto HP 12 MP menembus batas 5 MB backend dan
 * membuat unggahan lewat sinyal masjid bertele-tele, padahal yang dibutuhkan cuma
 * descriptor-nya. Storage server ikut ditolong: 200 jamaah x 3 foto itu giga-an.
 */
async function kecilkan(file: File): Promise<File> {
  try {
    const bitmap = await createImageBitmap(file);
    const skala = Math.min(1, MAKS_SISI / Math.max(bitmap.width, bitmap.height));
    if (skala === 1) {
      bitmap.close();
      return file;
    }

    const canvas = document.createElement("canvas");
    canvas.width = Math.round(bitmap.width * skala);
    canvas.height = Math.round(bitmap.height * skala);
    canvas.getContext("2d")!.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    bitmap.close();

    const blob = await new Promise<Blob | null>((r) => canvas.toBlob(r, "image/jpeg", 0.9));
    if (!blob) return file;

    return new File([blob], file.name.replace(/\.[^.]+$/, "") + ".jpg", { type: "image/jpeg" });
  } catch {
    // Format yang tidak bisa dibaca browser — HEIC mentah dari iPhone, misalnya.
    // Kirim apa adanya, biar backend yang menolak dengan alasannya sendiri.
    return file;
  }
}

interface Foto { id: number; path: string }
interface Jamaah { id: number; nama_lengkap: string; photos: Foto[] }

export default function WajahPage() {
  useRoleGuard(["super_admin", "admin"]);
  const { id } = useParams<{ id: string }>();
  const [jamaah, setJamaah] = useState<Jamaah | null>(null);
  const [pesan, setPesan] = useState("");
  const [error, setError] = useState("");
  const [uploading, setUploading] = useState(false);
  // Tiap foto menunggu face-service (timeout 15 detik di backend). Tanpa angka,
  // tombol diam belasan detik dan petugas mengira halamannya menggantung.
  const [progres, setProgres] = useState("");
  const [loading, setLoading] = useState(true);
  const [kamera, setKamera] = useState(false);
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

  function unggah(e: React.ChangeEvent<HTMLInputElement>) {
    const files = Array.from(e.target.files ?? []);
    e.target.value = "";
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
   * Satu per satu: backend menerima satu foto per request. Yang gagal tidak
   * boleh menghapus jejak yang berhasil — wajah tak terdeteksi di satu foto
   * itu biasa, dan petugas perlu tahu foto mana, bukan cuma bahwa ada yang
   * gagal. Pesan sukses diambil dari respons terakhir karena backend yang
   * menghitung total fotonya, bukan halaman ini.
   */
  async function kirim(fotos: File[]) {
    setError("");
    setPesan("");
    setUploading(true);

    const gagal: string[] = [];
    let terakhir = "";

    for (const [i, asli] of fotos.entries()) {
      setProgres(fotos.length > 1 ? ` ${i + 1}/${fotos.length}` : "");
      const foto = await kecilkan(asli);
      const body = new FormData();
      body.append("photo", foto, foto.name);
      try {
        terakhir = (await api(`/jamaahs/${id}/face-enroll`, { method: "POST", body })).message;
      } catch (err) {
        gagal.push(`${asli.name} — ${err instanceof Error ? err.message : "gagal unggah"}`);
      }
    }

    setProgres("");
    setUploading(false);
    setPesan(terakhir);
    setError(gagal.join(" · "));
    reload();
  }

  async function hapus(foto: Foto) {
    if (!confirm("Hapus foto ini beserta data wajahnya?")) return;
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
      <Link href="/jamaah" className="text-sm text-gray-500 hover:text-gray-700">← Kembali ke Data Jamaah</Link>
      <h2 className="text-xl font-bold text-gray-900">
        Foto Wajah — {jamaah?.nama_lengkap ?? "..."}
      </h2>
      <p className="text-sm text-gray-500">
        {total} foto tersimpan. Minimal 3 foto untuk face recognition
        {total < 3 && ` (kurang ${3 - total})`}.
      </p>

      {error && <p className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
      {pesan && <p className="rounded bg-emerald-50 p-2 text-sm text-emerald-700">{pesan}</p>}

      <div className="flex flex-wrap items-center gap-3">
        <label className="inline-block cursor-pointer rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
          {uploading ? `Memproses${progres}...` : "+ Ambil / Pilih Foto"}
          <input
            type="file"
            accept="image/*"
            multiple
            className="hidden"
            disabled={uploading}
            onChange={unggah}
          />
        </label>

        {/* Kamera dalam halaman: preview-nya tetap menyala antar jepretan, jadi
            beberapa pose bisa diambil tanpa buka-tutup pemilih berkas. */}
        <button
          onClick={() => setKamera((k) => !k)}
          className="rounded-lg border border-emerald-600 px-4 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-50"
        >
          {kamera ? "Tutup Kamera" : "Pakai Kamera"}
        </button>
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
