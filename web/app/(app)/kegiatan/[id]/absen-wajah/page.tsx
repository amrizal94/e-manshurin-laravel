"use client";

import { useEffect, useRef, useState } from "react";
import { useParams } from "next/navigation";
import Link from "next/link";
import { api } from "@/lib/api";

interface Hasil {
  ok: boolean;
  pesan: string;
  nama?: string;
  waktu: string;
}

export default function AbsenWajahPage() {
  const { id } = useParams<{ id: string }>();
  const videoRef = useRef<HTMLVideoElement>(null);
  const [siap, setSiap] = useState(false);
  const [proses, setProses] = useState(false);
  const [riwayat, setRiwayat] = useState<Hasil[]>([]);
  const [error, setError] = useState("");

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

  async function scan() {
    const video = videoRef.current;
    if (!video || proses) return;
    setProses(true);

    const canvas = document.createElement("canvas");
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext("2d")!.drawImage(video, 0, 0);

    const blob = await new Promise<Blob | null>((r) => canvas.toBlob(r, "image/jpeg", 0.9));
    if (!blob) {
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
      setProses(false);
    }
  }

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
          <video
            ref={videoRef}
            autoPlay
            playsInline
            muted
            className="w-full rounded-xl border border-gray-200 bg-black"
          />
          <button
            onClick={scan}
            disabled={!siap || proses}
            className="w-full rounded-lg bg-emerald-600 py-3 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
          >
            {proses ? "Memproses..." : "Scan Wajah"}
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
                Arahkan wajah ke kamera lalu tekan Scan
              </li>
            )}
          </ul>
        </div>
      </div>
    </div>
  );
}
