"use client";

import { useCallback, useEffect, useState } from "react";
import { useParams } from "next/navigation";
import Link from "next/link";
import { api } from "@/lib/api";

const API_ORIGIN = (process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api").replace(/\/api$/, "");

interface Foto { id: number; path: string }
interface Jamaah { id: number; nama_lengkap: string; photos: Foto[] }

export default function WajahPage() {
  const { id } = useParams<{ id: string }>();
  const [jamaah, setJamaah] = useState<Jamaah | null>(null);
  const [pesan, setPesan] = useState("");
  const [error, setError] = useState("");
  const [uploading, setUploading] = useState(false);
  const [loading, setLoading] = useState(true);

  const reload = useCallback(() => {
    api<Jamaah>(`/jamaahs/${id}`)
      .then((r) => setJamaah(r.data))
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [id]);

  useEffect(reload, [reload]);

  async function unggah(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    if (!file) return;
    e.target.value = "";
    setError("");
    setPesan("");
    setUploading(true);
    const body = new FormData();
    body.append("photo", file);
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
