"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { useParams } from "next/navigation";
import Link from "next/link";
import { api } from "@/lib/api";
import { useKamera } from "@/lib/useKamera";
import { useRoleGuard } from "@/lib/useRoleGuard";

const API_ORIGIN = (process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api").replace(/\/api$/, "");

interface Foto { id: number; path: string }
interface Jamaah { id: number; nama_lengkap: string; photos: Foto[] }

export default function WajahPage() {
  useRoleGuard(["super_admin", "admin"]);
  const { id } = useParams<{ id: string }>();
  const [jamaah, setJamaah] = useState<Jamaah | null>(null);
  const [pesan, setPesan] = useState("");
  const [error, setError] = useState("");
  const [uploading, setUploading] = useState(false);
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
    const file = e.target.files?.[0];
    if (!file) return;
    e.target.value = "";
    kirim(file, file.name);
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
    if (blob) kirim(blob, "wajah.jpg");
  }

  async function kirim(foto: Blob, nama: string) {
    setError("");
    setPesan("");
    setUploading(true);
    const body = new FormData();
    body.append("photo", foto, nama);
    try {
      const res = await api(`/jamaahs/${id}/face-enroll`, { method: "POST", body });
      setPesan(res.message);
      reload();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Gagal unggah");
    } finally {
      setUploading(false);
    }
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
          {uploading ? "Memproses..." : "+ Ambil / Pilih Foto"}
          <input
            type="file"
            accept="image/*"
            capture="user"
            className="hidden"
            disabled={uploading}
            onChange={unggah}
          />
        </label>

        {/* Di HP tombol di atas sudah membuka kamera; di laptop `capture` diabaikan
            browser, jadi tanpa ini foto harus diambil dari perangkat lain dulu. */}
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
