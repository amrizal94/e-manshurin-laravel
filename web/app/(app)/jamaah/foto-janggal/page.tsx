"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { useRoleGuard } from "@/lib/useRoleGuard";

interface Baris {
  jamaah_id: number;
  nama_lengkap: string;
  kelompok: string | null;
  skor: number;
  jumlah_foto: number;
}

export default function FotoJanggalPage() {
  useRoleGuard(["super_admin", "admin"]);
  const [rows, setRows] = useState<Baris[] | null>(null);
  const [error, setError] = useState("");

  useEffect(() => {
    api<Baris[]>("/jamaahs/foto-janggal")
      .then((res) => setRows(res.data))
      .catch((err) => setError(err.message));
  }, []);

  return (
    <div className="space-y-4">
      <Link href="/dashboard" className="text-sm text-gray-500 hover:text-gray-700">← Kembali ke Dashboard</Link>
      <h2 className="text-xl font-bold text-gray-900">Foto Wajah Perlu Dicek</h2>

      <div className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
        <p>
          Jamaah di bawah ini punya foto yang <strong>tidak saling mengenali</strong>.
          Biasanya karena ada foto orang lain yang terlanjur tersimpan di kartunya. Kalau
          dibiarkan, kiosk bisa mencatat hadir atas nama orang yang tidak datang.
        </p>
        <p className="mt-2">
          Buka fotonya, lihat satu per satu, lalu hapus yang bukan orangnya. Tidak ada yang
          dihapus otomatis — dari angkanya saja mustahil tahu foto mana dari sepasang itu
          yang salah.
        </p>
      </div>

      {error && <p className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
      {!rows && !error && <p className="text-sm text-gray-400">Memuat...</p>}

      {rows?.length === 0 && (
        <p className="rounded-xl border border-gray-200 bg-white p-4 text-sm text-gray-600">
          Tidak ada yang janggal. Semua jamaah yang punya lebih dari satu foto, foto-fotonya
          saling mengenali.
        </p>
      )}

      {rows && rows.length > 0 && (
        <ul className="space-y-2">
          {rows.map((r) => (
            <li key={r.jamaah_id}>
              <Link
                href={`/jamaah/${r.jamaah_id}/wajah`}
                className="flex items-center justify-between gap-3 rounded-xl border border-gray-200 bg-white p-4 hover:border-emerald-400"
              >
                <span className="min-w-0">
                  <span className="block truncate font-semibold text-gray-900">{r.nama_lengkap}</span>
                  <span className="text-sm text-gray-500">
                    {r.kelompok ?? "Tanpa kelompok"} · {r.jumlah_foto} foto
                  </span>
                </span>
                {/* Angkanya ikut ditampilkan supaya urutan periksanya jelas: yang paling
                    kecil paling mungkin benar-benar foto orang lain. */}
                <span className="shrink-0 text-right">
                  <span className="block text-lg font-bold text-amber-700">
                    {Math.round(r.skor * 100)}%
                  </span>
                  <span className="text-xs text-gray-400">kemiripan</span>
                </span>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
