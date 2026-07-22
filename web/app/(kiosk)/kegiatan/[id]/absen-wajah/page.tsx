"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { useParams } from "next/navigation";
import Link from "next/link";
import * as faceapi from "face-api.js";
import { api } from "@/lib/api";
import { eyeAspectRatio, EAR_OPEN, EAR_CLOSED } from "@/lib/ear.mjs";

interface Hasil {
  ok: boolean;
  pesan: string;
  nama?: string;
  waktu: string;
}

type LivenessState = "waiting" | "eyesOpen" | "blinking" | "passed";

const LABEL_LIVENESS: Record<LivenessState, string> = {
  waiting: "Arahkan wajah ke kamera...",
  eyesOpen: "Kedipkan mata untuk verifikasi...",
  blinking: "Kedipkan mata untuk verifikasi...",
  passed: "Terverifikasi ✓ — memproses...",
};

export default function AbsenWajahPage() {
  const { id } = useParams<{ id: string }>();
  const videoRef = useRef<HTMLVideoElement>(null);
  const [siap, setSiap] = useState(false);
  const [proses, setProses] = useState(false);
  const [riwayat, setRiwayat] = useState<Hasil[]>([]);
  const [error, setError] = useState("");
  const [modelSiap, setModelSiap] = useState(false);
  const [liveness, setLiveness] = useState<LivenessState>("waiting");
  const prosesRef = useRef(false);

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
    faceapi.nets.tinyFaceDetector
      .loadFromUri("/models")
      .then(() => faceapi.nets.faceLandmark68TinyNet.loadFromUri("/models"))
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
      const res = await api<{ jamaah: { nama_lengkap: string } }>(
        `/kegiatans/${id}/absensi-wajah`,
        { method: "POST", body }
      );
      setRiwayat((r) => [{ ok: true, pesan: res.message, nama: res.data.jamaah.nama_lengkap, waktu }, ...r].slice(0, 20));
    } catch (err) {
      setRiwayat((r) => [{ ok: false, pesan: err instanceof Error ? err.message : "Gagal", waktu }, ...r].slice(0, 20));
    } finally {
      prosesRef.current = false;
      setProses(false);
      setLiveness("waiting"); // wajib kedip baru lagi untuk scan berikutnya
    }
  }, [id]);

  // Loop deteksi kedipan mata — cegah foto statis di layar HP lain lolos absen.
  useEffect(() => {
    if (!modelSiap || !siap) return;
    let batal = false;

    async function loop() {
      if (batal) return;
      const video = videoRef.current;

      if (video && !prosesRef.current) {
        const hasil = await faceapi
          .detectSingleFace(video, new faceapi.TinyFaceDetectorOptions())
          .withFaceLandmarks(true);

        if (!hasil) {
          setLiveness("waiting");
        } else {
          const nilaiEar = (eyeAspectRatio(hasil.landmarks.getLeftEye()) + eyeAspectRatio(hasil.landmarks.getRightEye())) / 2;

          setLiveness((state) => {
            if (state === "waiting" && nilaiEar > EAR_OPEN) return "eyesOpen";
            if (state === "eyesOpen" && nilaiEar < EAR_CLOSED) return "blinking";
            if (state === "blinking" && nilaiEar > EAR_OPEN) return "passed";
            return state;
          });
        }
      }

      setTimeout(loop, 150);
    }
    loop();

    return () => { batal = true; };
  }, [modelSiap, siap]);

  useEffect(() => {
    if (liveness === "passed") scan();
  }, [liveness, scan]);

  const terakhir = riwayat[0];

  return (
    <div className="relative flex min-h-screen flex-col items-center gap-4 p-4 text-white sm:gap-6 sm:p-8">
      <Link
        href={`/kegiatan/${id}`}
        className="absolute left-4 top-4 text-xs text-gray-500 hover:text-gray-300 sm:text-sm"
      >
        ← Kembali ke daftar peserta
      </Link>

      <h1 className="mt-8 text-xl font-bold sm:mt-0 sm:text-3xl">Absen Wajah</h1>

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
            <p className="text-base font-semibold sm:text-2xl">
              {modelSiap ? LABEL_LIVENESS[liveness] : "Memuat model deteksi wajah..."}
            </p>
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
        {proses ? "Memproses..." : "Scan manual (tanpa verifikasi kedip)"}
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
              Arahkan wajah ke kamera dan kedipkan mata untuk absen otomatis
            </li>
          )}
        </ul>
      </div>
    </div>
  );
}
