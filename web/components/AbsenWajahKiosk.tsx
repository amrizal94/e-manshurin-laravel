"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import Link from "next/link";
import * as faceapi from "face-api.js";
import { api, ApiError } from "@/lib/api";

interface Hasil {
  ok: boolean;
  pesan: string;
  nama?: string;
  waktu: string;
}

interface KegiatanRingkas {
  id: number;
  nama: string;
  jenis_pengajian: string;
  jam_mulai: string | null;
  jam_selesai: string | null;
}

interface Jadwal {
  wilayah: string;
  aktif: KegiatanRingkas[];
  berikutnya: KegiatanRingkas | null;
}

const STABIL_MS = 1000;
// Sekali wajah sukses discan, jangan diulang selama orangnya masih di depan kamera —
// tapi kalau lebih dari PLAFON_MS berlalu, paksa scan lagi (jaga-jaga jangan sampai macet
// permanen kalau deteksi salah kira "masih orang yang sama").
const PLAFON_MS = 6000;
// inputSize kecil = deteksi jauh lebih cepat, cukup akurat buat wajah dekat kamera (kiosk).
const DETEKSI_OPTIONS = new faceapi.TinyFaceDetectorOptions({ inputSize: 224 });
// Berapa lama nama panggilan tetap nempel di box setelah match sukses.
const OVERLAY_NAMA_MS = 3000;
// Kiosk standby menyala seharian; jadwal cukup dicek semenit sekali.
const POLL_JADWAL_MS = 60_000;

function isIOSSafari(): boolean {
  if (typeof navigator === "undefined") return false;
  const ua = navigator.userAgent;
  const iOS = /iPad|iPhone|iPod/.test(ua) || (ua.includes("Macintosh") && navigator.maxTouchPoints > 1);
  return iOS && !/CriOS|FxiOS|EdgiOS|OPiOS/.test(ua);
}

function gambarBox(canvas: HTMLCanvasElement, box: faceapi.Box, label: string) {
  const ctx = canvas.getContext("2d");
  if (!ctx) return;
  ctx.clearRect(0, 0, canvas.width, canvas.height);
  ctx.strokeStyle = "#34d399";
  ctx.lineWidth = 3;
  ctx.strokeRect(box.x, box.y, box.width, box.height);
  ctx.fillStyle = "#34d399";
  ctx.font = "16px sans-serif";
  ctx.fillText(label, box.x, box.y > 20 ? box.y - 6 : box.y + 16);
}

const AMBANG_ANAK = 15; // SMP ke bawah
const AMBANG_TUA = 40;

/** Tanpa tanggal lahir (usia null), pakai kategori_usia sebagai perkiraan — default aman ke "muda", bukan "tua". */
function tentukanSapaan(jenisKelamin: "L" | "P", usia: number | null, kategoriUsia: string): string {
  let kelompok: "anak" | "muda" | "tua";
  if (usia != null) {
    kelompok = usia <= AMBANG_ANAK ? "anak" : usia <= AMBANG_TUA ? "muda" : "tua";
  } else {
    const anak = kategoriUsia === "paud_tk" || kategoriUsia === "caberawit" || kategoriUsia === "praremaja";
    kelompok = anak ? "anak" : "muda";
  }
  if (kelompok === "anak") return "Dek";
  if (kelompok === "tua") return jenisKelamin === "L" ? "Pak" : "Bu";
  return jenisKelamin === "L" ? "Mas" : "Mbak";
}

function ucapkanTerimaKasih(nama: string, jenisKelamin: "L" | "P", usia: number | null, kategoriUsia: string) {
  if (typeof window === "undefined" || !("speechSynthesis" in window)) return;
  const doa = jenisKelamin === "L" ? "Alhamdulillah, Jazakallahu Khoiro" : "Alhamdulillah, Jazakillahu Khoiro";
  const sapaan = tentukanSapaan(jenisKelamin, usia, kategoriUsia);
  const utter = new SpeechSynthesisUtterance(`${doa}, ${sapaan} ${nama}, sudah absen`);
  utter.lang = "id-ID";
  window.speechSynthesis.cancel(); // potong ucapan sebelumnya biar tidak menumpuk kalau scan beruntun
  window.speechSynthesis.speak(utter);
}

function jam(nilai: string | null): string {
  return nilai ? nilai.slice(0, 5) : "";
}

/** "Pengajian Umum pukul 19.30" — dipakai di layar idle, jadi ditulis seperti orang bicara. */
function kalimatJadwal(k: KegiatanRingkas): string {
  return k.jam_mulai ? `${k.nama} pukul ${jam(k.jam_mulai).replace(":", ".")}` : k.nama;
}

/**
 * Kiosk absen wajah. Tanpa `kegiatanId` kiosk jalan standby: kegiatan ditentukan
 * server dari jam sekarang, dan saat tidak ada jadwal kamera dimatikan.
 */
export default function AbsenWajahKiosk({ kegiatanId }: { kegiatanId?: string }) {
  const standby = !kegiatanId;
  const videoRef = useRef<HTMLVideoElement>(null);
  const canvasRef = useRef<HTMLCanvasElement>(null);
  const [siap, setSiap] = useState(false);
  const [proses, setProses] = useState(false);
  const [riwayat, setRiwayat] = useState<Hasil[]>([]);
  const [error, setError] = useState("");
  const [modelSiap, setModelSiap] = useState(false);
  const [wajahTerdeteksi, setWajahTerdeteksi] = useState(false);
  const [suaraAktif, setSuaraAktif] = useState(false);
  const [sesiHabis, setSesiHabis] = useState(false);
  const [jadwal, setJadwal] = useState<Jadwal | null>(null);
  const prosesRef = useRef(false);
  const stabilSejakRef = useRef<number | null>(null);
  const sudahDiprosesRef = useRef(false);
  const scanTerakhirRef = useRef(0);
  const namaOverlayRef = useRef<{ nama: string; sampaiMs: number } | null>(null);

  // Kiosk terkunci ke satu kegiatan tidak perlu tahu jadwal; standby menunggu poll pertama.
  const adaKegiatan = standby ? (jadwal?.aktif.length ?? 0) > 0 : true;
  const menungguJadwal = standby && jadwal === null;

  const muatJadwal = useCallback(async () => {
    try {
      const res = await api<Jadwal>("/kegiatan-aktif");
      setJadwal(res.data);
    } catch (err) {
      if (err instanceof ApiError && err.status === 401) setSesiHabis(true);
      // error lain (jaringan putus sesaat): pertahankan jadwal terakhir, coba lagi poll berikutnya
    }
  }, []);

  useEffect(() => {
    if (!standby || sesiHabis) return;
    muatJadwal();
    const timer = setInterval(muatJadwal, POLL_JADWAL_MS);
    return () => clearInterval(timer);
  }, [standby, sesiHabis, muatJadwal]);

  // Kamera hanya menyala saat ada kegiatan — kiosk standby seharian tidak perlu
  // lampu kamera nyala terus di jam kosong.
  useEffect(() => {
    if (!adaKegiatan || sesiHabis) {
      setSiap(false);
      return;
    }

    const video = videoRef.current;
    let batal = false;
    navigator.mediaDevices
      .getUserMedia({ video: { facingMode: "user", width: 640, height: 480 } })
      .then((s) => {
        // komponen sudah unmount sebelum kamera siap — jangan nyalakan, langsung matikan lagi
        if (batal) {
          s.getTracks().forEach((t) => t.stop());
          return;
        }
        if (video) {
          video.srcObject = s;
          setSiap(true);
        }
      })
      .catch(() => setError("Kamera tidak dapat diakses. Izinkan akses kamera di browser."));

    return () => {
      batal = true;
      const stream = video?.srcObject;
      if (stream instanceof MediaStream) stream.getTracks().forEach((t) => t.stop());
    };
  }, [adaKegiatan, sesiHabis]);

  useEffect(() => {
    // iOS Safari blokir speechSynthesis tanpa tap user langsung — device lain bisa langsung aktif otomatis
    if (isIOSSafari() || typeof window === "undefined" || !("speechSynthesis" in window)) return;
    window.speechSynthesis.speak(new SpeechSynthesisUtterance(""));
    setSuaraAktif(true);
  }, []);

  useEffect(() => {
    // ponytail: webgl tfjs kerap hang di Safari iOS — cpu cuma dipakai di sana, device lain tetap webgl (lebih cepat)
    faceapi.tf
      .setBackend(isIOSSafari() ? "cpu" : "webgl")
      .catch(() => faceapi.tf.setBackend("cpu"))
      .then(() => faceapi.tf.ready())
      .then(() => faceapi.nets.tinyFaceDetector.loadFromUri("/models"))
      .then(() => setModelSiap(true))
      .catch(() => setError("Model deteksi wajah gagal dimuat."));
  }, []);

  const scan = useCallback(async () => {
    const video = videoRef.current;
    if (!video || prosesRef.current) return;
    prosesRef.current = true;
    setProses(true);

    const canvas = document.createElement("canvas");
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext("2d")!.drawImage(video, 0, 0);

    const blob = await new Promise<Blob | null>((r) => canvas.toBlob(r, "image/jpeg", 0.9));
    if (!blob) {
      prosesRef.current = false;
      setProses(false);
      return;
    }

    const body = new FormData();
    body.append("photo", blob, "scan.jpg");

    const waktu = new Date().toLocaleTimeString("id-ID");
    try {
      const res = await api<{
        jamaah: {
          nama_lengkap: string;
          nama_panggilan?: string;
          jenis_kelamin: "L" | "P";
          usia: number | null;
          kategori_usia: string;
        };
      }>(kegiatanId ? `/kegiatans/${kegiatanId}/absensi-wajah` : "/absensi-wajah", { method: "POST", body });
      const { nama_lengkap, nama_panggilan, jenis_kelamin, usia, kategori_usia } = res.data.jamaah;
      const nama = nama_panggilan || nama_lengkap;
      namaOverlayRef.current = { nama, sampaiMs: Date.now() + OVERLAY_NAMA_MS };
      ucapkanTerimaKasih(nama, jenis_kelamin, usia, kategori_usia);
      setRiwayat((r) => [{ ok: true, pesan: res.message, nama: nama_lengkap, waktu }, ...r].slice(0, 20));
    } catch (err) {
      if (err instanceof ApiError && err.status === 401) {
        setSesiHabis(true);
      } else if (err instanceof ApiError && err.status === 409) {
        muatJadwal(); // jadwal habis di tengah sesi — langsung kembali ke layar idle
      } else {
        setRiwayat((r) => [{ ok: false, pesan: err instanceof Error ? err.message : "Gagal", waktu }, ...r].slice(0, 20));
      }
    } finally {
      prosesRef.current = false;
      setProses(false);
    }
  }, [kegiatanId, muatJadwal]);

  // Auto-scan begitu wajah terdeteksi stabil — kiosk diawasi panitia, tidak perlu verifikasi kedip.
  useEffect(() => {
    if (!modelSiap || !siap || sesiHabis || !adaKegiatan) return;
    let batal = false;

    async function loop() {
      if (batal) return;
      const video = videoRef.current;

      if (video && !prosesRef.current) {
        try {
          if (canvasRef.current && video.videoWidth) {
            if (canvasRef.current.width !== video.videoWidth) canvasRef.current.width = video.videoWidth;
            if (canvasRef.current.height !== video.videoHeight) canvasRef.current.height = video.videoHeight;
          }

          const timeout = new Promise<"timeout">((resolve) => setTimeout(() => resolve("timeout"), 2000));
          const deteksi = faceapi.detectSingleFace(video, DETEKSI_OPTIONS);
          const hasil = await Promise.race([deteksi, timeout]);

          if (hasil === "timeout" || !hasil) {
            setWajahTerdeteksi(false);
            stabilSejakRef.current = null;
            sudahDiprosesRef.current = false;
            if (canvasRef.current) {
              canvasRef.current.getContext("2d")?.clearRect(0, 0, canvasRef.current.width, canvasRef.current.height);
            }
          } else {
            setWajahTerdeteksi(true);
            if (canvasRef.current) {
              const skorPersen = Math.round(hasil.score * 100);
              const overlay = namaOverlayRef.current && namaOverlayRef.current.sampaiMs > Date.now()
                ? namaOverlayRef.current.nama
                : null;
              gambarBox(canvasRef.current, hasil.box, overlay ? `${overlay} (${skorPersen}%)` : `${skorPersen}%`);
            }
            if (stabilSejakRef.current === null) stabilSejakRef.current = Date.now();
            const stabilMs = Date.now() - stabilSejakRef.current;
            const sejakScanTerakhir = Date.now() - scanTerakhirRef.current;
            const bolehScan = !sudahDiprosesRef.current || sejakScanTerakhir >= PLAFON_MS;
            if (stabilMs >= STABIL_MS && bolehScan) {
              sudahDiprosesRef.current = true;
              scanTerakhirRef.current = Date.now();
              scan();
            }
          }
        } catch {
          // deteksi transien gagal — abaikan, coba lagi loop berikutnya
        }
      }

      if (!batal) setTimeout(loop, 150);
    }
    loop();

    return () => { batal = true; };
  }, [modelSiap, siap, scan, sesiHabis, adaKegiatan]);

  function aktifkanSuara() {
    if (typeof window === "undefined" || !("speechSynthesis" in window)) return;
    // ucapan kosong dipicu langsung dari tap user — buka izin autoplay audio di Safari/iOS
    window.speechSynthesis.speak(new SpeechSynthesisUtterance(""));
    setSuaraAktif(true);
  }

  const terakhir = riwayat[0];
  const label = !modelSiap
    ? "Memuat model deteksi wajah..."
    : proses
      ? "Memproses..."
      : wajahTerdeteksi
        ? "Wajah terdeteksi, tahan sebentar..."
        : "Arahkan wajah ke kamera...";

  const jalanKembali = kegiatanId ? `/kegiatan/${kegiatanId}/absen-wajah` : "/absen-wajah";

  if (sesiHabis) {
    return (
      <div className="flex min-h-screen flex-col items-center justify-center gap-4 p-8 text-center text-white">
        <p className="text-2xl font-bold">Sesi berakhir</p>
        <p className="max-w-sm text-sm text-gray-400">
          Sesi login sudah tidak berlaku. Absen wajah dihentikan sementara — login ulang untuk melanjutkan.
        </p>
        <Link
          href={`/login?redirect=${encodeURIComponent(jalanKembali)}`}
          className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold hover:bg-emerald-700"
        >
          Login Ulang
        </Link>
      </div>
    );
  }

  return (
    <div className="relative flex min-h-screen flex-col items-center gap-4 p-4 text-white sm:gap-6 sm:p-8">
      {kegiatanId && (
        <Link
          href={`/kegiatan/${kegiatanId}`}
          className="absolute left-4 top-4 text-xs text-gray-500 hover:text-gray-300 sm:text-sm"
        >
          ← Kembali ke daftar peserta
        </Link>
      )}

      {/* di mode terkunci, judul digeser turun supaya tidak tertimpa link "Kembali" di layar HP */}
      <h1 className={`text-xl font-bold sm:text-3xl ${kegiatanId ? "mt-8 sm:mt-0" : ""}`}>Absen Wajah</h1>

      {standby && jadwal && (
        <p className="-mt-2 text-center text-xs text-gray-500">
          Kiosk: {jadwal.wilayah}
        </p>
      )}

      {standby && adaKegiatan && (
        <p className="-mt-1 text-center text-sm text-emerald-300">
          {jadwal!.aktif.map((k) => k.nama).join(" · ")}
        </p>
      )}

      {!suaraAktif && (
        <button
          onClick={aktifkanSuara}
          className="rounded-lg border border-emerald-700 bg-emerald-500/10 px-4 py-2 text-sm font-medium text-emerald-300 hover:bg-emerald-500/20"
        >
          🔊 Aktifkan Suara
        </button>
      )}

      {error && (
        <p className="rounded-lg bg-red-500/10 px-4 py-2 text-sm text-red-300">{error}</p>
      )}

      <div className="relative w-full max-w-2xl">
        <video
          ref={videoRef}
          autoPlay
          playsInline
          muted
          className="aspect-[4/3] w-full rounded-2xl border-4 border-gray-800 bg-black object-cover"
        />
        <canvas ref={canvasRef} className="pointer-events-none absolute inset-0 aspect-[4/3] w-full" />

        {!adaKegiatan && (
          <div className="absolute inset-0 flex flex-col items-center justify-center gap-3 rounded-2xl bg-black/90 px-6 text-center">
            {menungguJadwal ? (
              <p className="text-sm text-gray-500">Memeriksa jadwal...</p>
            ) : (
              <>
                <p className="text-xl font-bold sm:text-3xl">Belum ada pengajian saat ini</p>
                <p className="text-sm text-gray-400 sm:text-base">
                  {jadwal?.berikutnya
                    ? `Jadwal berikutnya: ${kalimatJadwal(jadwal.berikutnya)}`
                    : "Tidak ada jadwal lagi hari ini."}
                </p>
                <p className="mt-2 text-xs text-gray-600">
                  Kamera menyala otomatis saat pengajian dimulai.
                </p>
              </>
            )}
          </div>
        )}

        {siap && adaKegiatan && (
          <div className="absolute inset-x-0 bottom-0 rounded-b-2xl bg-black/70 px-4 py-3 text-center sm:py-5">
            <p className="text-base font-semibold sm:text-2xl">{label}</p>
          </div>
        )}
      </div>

      {terakhir && (
        <div
          className={`w-full max-w-2xl rounded-xl px-4 py-3 text-center text-sm font-medium sm:text-base ${
            terakhir.ok ? "bg-emerald-500/15 text-emerald-300" : "bg-red-500/15 text-red-300"
          }`}
        >
          {terakhir.ok ? `✓ ${terakhir.nama} — ${terakhir.pesan}` : `✗ ${terakhir.pesan}`}
          <span className="ml-2 text-xs text-gray-500">{terakhir.waktu}</span>
        </div>
      )}

      <button
        onClick={scan}
        disabled={!siap || proses || !adaKegiatan}
        className="rounded-xl border border-gray-700 px-6 py-3 text-xs font-medium text-gray-400 hover:bg-gray-900 disabled:opacity-50 sm:text-sm"
      >
        {proses ? "Memproses..." : "Scan manual"}
      </button>

      <div className="w-full max-w-2xl flex-1">
        <p className="mb-2 text-xs uppercase tracking-wide text-gray-500">Riwayat</p>
        <ul className="max-h-64 divide-y divide-gray-800 overflow-y-auto rounded-xl border border-gray-800">
          {riwayat.slice(1).map((h, i) => (
            <li key={i} className="flex items-center justify-between p-3 text-sm">
              <span className={h.ok ? "text-emerald-400" : "text-red-400"}>
                {h.ok ? `✓ ${h.nama}` : `✗ ${h.pesan}`}
              </span>
              <span className="text-xs text-gray-500">{h.waktu}</span>
            </li>
          ))}
          {riwayat.length <= 1 && (
            <li className="p-6 text-center text-sm text-gray-600">
              Arahkan wajah ke kamera untuk absen otomatis
            </li>
          )}
        </ul>
      </div>
    </div>
  );
}
