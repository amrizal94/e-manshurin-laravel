"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { useParams } from "next/navigation";
import Link from "next/link";
import * as faceapi from "face-api.js";
import { api } from "@/lib/api";

interface Hasil {
  ok: boolean;
  pesan: string;
  nama?: string;
  waktu: string;
}

const STABIL_MS = 1000;
const HILANG_GRACE_MS = 800;
const LOMPAT_POSISI_RASIO = 0.5; // pergeseran kotak wajah > 50% lebar kotak = dianggap wajah lain

function ucapkanTerimaKasih(nama: string) {
  if (typeof window === "undefined" || !("speechSynthesis" in window)) return;
  const utter = new SpeechSynthesisUtterance(`Terima kasih, ${nama}, sudah hadir`);
  utter.lang = "id-ID";
  window.speechSynthesis.cancel(); // potong ucapan sebelumnya biar tidak menumpuk kalau scan beruntun
  window.speechSynthesis.speak(utter);
}

export default function AbsenWajahPage() {
  const { id } = useParams<{ id: string }>();
  const videoRef = useRef<HTMLVideoElement>(null);
  const [siap, setSiap] = useState(false);
  const [proses, setProses] = useState(false);
  const [riwayat, setRiwayat] = useState<Hasil[]>([]);
  const [error, setError] = useState("");
  const [modelSiap, setModelSiap] = useState(false);
  const [wajahTerdeteksi, setWajahTerdeteksi] = useState(false);
  const [suaraAktif, setSuaraAktif] = useState(false);
  const prosesRef = useRef(false);
  const stabilSejakRef = useRef<number | null>(null);
  const sudahDiprosesRef = useRef(false);
  const hilangSejakRef = useRef<number | null>(null);
  const posisiTerakhirRef = useRef<{ x: number; y: number; width: number } | null>(null);

  useEffect(() => {
    let stream: MediaStream | null = null;
    navigator.mediaDevices
      .getUserMedia({ video: { facingMode: "user", width: 640, height: 480 } })
      .then((s) => {
        stream = s;
        if (videoRef.current) {
          videoRef.current.srcObject = s;
          setSiap(true);
        }
      })
      .catch(() => setError("Kamera tidak dapat diakses. Izinkan akses kamera di browser."));

    return () => stream?.getTracks().forEach((t) => t.stop());
  }, []);

  useEffect(() => {
    // ponytail: backend webgl tfjs kerap hang di Safari iOS — paksa cpu, model tiny ini cukup ringan
    faceapi.tf
      .setBackend("cpu")
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
      const res = await api<{ jamaah: { nama_lengkap: string; nama_panggilan?: string } }>(
        `/kegiatans/${id}/absensi-wajah`,
        { method: "POST", body }
      );
      const { nama_lengkap, nama_panggilan } = res.data.jamaah;
      ucapkanTerimaKasih(nama_panggilan || nama_lengkap);
      setRiwayat((r) => [{ ok: true, pesan: res.message, nama: nama_lengkap, waktu }, ...r].slice(0, 20));
    } catch (err) {
      setRiwayat((r) => [{ ok: false, pesan: err instanceof Error ? err.message : "Gagal", waktu }, ...r].slice(0, 20));
    } finally {
      prosesRef.current = false;
      setProses(false);
    }
  }, [id]);

  // Auto-scan begitu wajah terdeteksi stabil — kiosk diawasi panitia, tidak perlu verifikasi kedip.
  useEffect(() => {
    if (!modelSiap || !siap) return;
    let batal = false;

    async function loop() {
      if (batal) return;
      const video = videoRef.current;

      if (video && !prosesRef.current) {
        try {
          const timeout = new Promise<"timeout">((resolve) => setTimeout(() => resolve("timeout"), 2000));
          const deteksi = faceapi.detectSingleFace(video, new faceapi.TinyFaceDetectorOptions());
          const hasil = await Promise.race([deteksi, timeout]);

          if (hasil === "timeout" || !hasil) {
            // toleransi: deteksi kadang meleset 1 frame walau wajah masih di kamera —
            // jangan langsung anggap orangnya pergi, tunggu absen beneran-beneran dulu.
            if (hilangSejakRef.current === null) hilangSejakRef.current = Date.now();
            if (Date.now() - hilangSejakRef.current >= HILANG_GRACE_MS) {
              setWajahTerdeteksi(false);
              stabilSejakRef.current = null;
              sudahDiprosesRef.current = false;
              posisiTerakhirRef.current = null;
            }
          } else {
            hilangSejakRef.current = null;

            const box = hasil.box;
            const posisi = posisiTerakhirRef.current;
            const gantiOrang =
              posisi !== null &&
              (Math.abs(box.x - posisi.x) > posisi.width * LOMPAT_POSISI_RASIO ||
                Math.abs(box.y - posisi.y) > posisi.width * LOMPAT_POSISI_RASIO);
            posisiTerakhirRef.current = { x: box.x, y: box.y, width: box.width };

            if (gantiOrang) {
              // wajah baru masuk pas-pasan wajah lama keluar, tanpa jeda "tidak terdeteksi" —
              // kotak wajah melompat jauh, anggap ini orang lain, mulai sesi baru.
              stabilSejakRef.current = Date.now();
              sudahDiprosesRef.current = false;
            } else if (stabilSejakRef.current === null) {
              stabilSejakRef.current = Date.now();
            }

            setWajahTerdeteksi(true);
            const stabilMs = Date.now() - stabilSejakRef.current;
            if (stabilMs >= STABIL_MS && !sudahDiprosesRef.current) {
              sudahDiprosesRef.current = true;
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
  }, [modelSiap, siap, scan]);

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

  return (
    <div className="relative flex min-h-screen flex-col items-center gap-4 p-4 text-white sm:gap-6 sm:p-8">
      <Link
        href={`/kegiatan/${id}`}
        className="absolute left-4 top-4 text-xs text-gray-500 hover:text-gray-300 sm:text-sm"
      >
        ← Kembali ke daftar peserta
      </Link>

      <h1 className="mt-8 text-xl font-bold sm:mt-0 sm:text-3xl">Absen Wajah</h1>

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
        {siap && (
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
        disabled={!siap || proses}
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
