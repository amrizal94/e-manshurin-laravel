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

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h2 className="text-xl font-bold text-gray-900">Absen Wajah</h2>
        <Link href={`/kegiatan/${id}`} className="text-sm text-emerald-600 hover:text-emerald-800">
          ← Kembali ke daftar peserta
        </Link>
      </div>

      {error && <p className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}

      <div className="grid gap-4 lg:grid-cols-2">
        <div className="space-y-3">
          <div className="relative">
            <video
              ref={videoRef}
              autoPlay
              playsInline
              muted
              className="w-full rounded-xl border border-gray-200 bg-black"
            />
            {siap && (
              <span className="absolute bottom-3 left-3 rounded-lg bg-black/60 px-3 py-1.5 text-xs font-medium text-white">
                {modelSiap ? LABEL_LIVENESS[liveness] : "Memuat model deteksi wajah..."}
              </span>
            )}
          </div>
          <button
            onClick={scan}
            disabled={!siap || proses}
            className="w-full rounded-lg border border-gray-300 py-2 text-xs font-medium text-gray-500 hover:bg-gray-50 disabled:opacity-50"
          >
            {proses ? "Memproses..." : "Scan manual (tanpa verifikasi kedip)"}
          </button>
        </div>

        <div className="rounded-xl border border-gray-200 bg-white">
          <h3 className="border-b border-gray-200 p-3 text-sm font-semibold">Riwayat Scan</h3>
          <ul className="max-h-96 divide-y divide-gray-100 overflow-y-auto">
            {riwayat.map((h, i) => (
              <li key={i} className="flex items-center justify-between p-3 text-sm">
                <span className={h.ok ? "text-emerald-700" : "text-red-600"}>
                  {h.ok ? `✓ ${h.nama}` : `✗ ${h.pesan}`}
                </span>
                <span className="text-xs text-gray-400">{h.waktu}</span>
              </li>
            ))}
            {riwayat.length === 0 && (
              <li className="p-6 text-center text-sm text-gray-400">
                Arahkan wajah ke kamera dan kedipkan mata untuk absen otomatis
              </li>
            )}
          </ul>
        </div>
      </div>
    </div>
  );
}
