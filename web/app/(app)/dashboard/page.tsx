"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { KATEGORI_USIA } from "@/lib/labels";

interface DashboardData {
  total_jamaah: number;
  total_tidak_aktif: number;
  total_mubaligh: number;
  total_laki: number;
  total_perempuan: number;
  total_kk: number;
  belum_masuk_keluarga: number;
  jumlah_daerah: number | null;
  jumlah_desa: number | null;
  jumlah_kelompok: number | null;
  per_kategori_usia: Record<string, number>;
  kegiatan_bulan_ini: number;
}

function StatTile({ label, value }: { label: string; value: number }) {
  return (
    <div className="rounded-xl border border-gray-200 bg-white p-4">
      <p className="text-sm text-gray-500">{label}</p>
      <p className="mt-1 text-3xl font-bold text-gray-900">{value}</p>
    </div>
  );
}

/**
 * Angka yang sekaligus jadi pekerjaan. Selama masih ada isinya dia menonjol dan bisa
 * diklik langsung ke daftarnya; begitu nol dia jadi tenang seperti tile biasa.
 */
function StatTugas({ label, value, href }: { label: string; value: number; href: string }) {
  if (value === 0) return <StatTile label={label} value={value} />;

  return (
    <Link href={href}
      className="rounded-xl border border-amber-300 bg-amber-50 p-4 hover:border-amber-400">
      <p className="text-sm text-amber-800">{label}</p>
      <p className="mt-1 text-3xl font-bold text-amber-900">{value}</p>
      <p className="mt-1 text-xs text-amber-700">Klik untuk merapikan</p>
    </Link>
  );
}

export default function DashboardPage() {
  const [data, setData] = useState<DashboardData | null>(null);
  const [error, setError] = useState("");

  useEffect(() => {
    api<DashboardData>("/dashboard")
      .then((res) => setData(res.data))
      .catch((err) => setError(err.message));
  }, []);

  if (error) return <p className="text-red-600">{error}</p>;
  if (!data) return <p className="text-gray-500">Memuat...</p>;

  const kategori = Object.entries(KATEGORI_USIA)
    .map(([key, label]) => ({ label, total: data.per_kategori_usia[key] ?? 0 }))
    .sort((a, b) => b.total - a.total);
  const max = Math.max(1, ...kategori.map((k) => k.total));
  // Yang null berarti di luar jangkauan akun ini — akun kelompok tidak dapat satu pun.
  const wilayah: [string, number][] = ([
    ["Daerah", data.jumlah_daerah],
    ["Desa", data.jumlah_desa],
    ["Kelompok", data.jumlah_kelompok],
  ] satisfies [string, number | null][]).flatMap(([label, total]) =>
    total === null ? [] : [[label, total] as [string, number]]
  );

  return (
    <div className="space-y-6">
      <h2 className="text-xl font-bold text-gray-900">Dashboard</h2>

      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <StatTile label="Jamaah Aktif" value={data.total_jamaah} />
        <StatTile label="Laki-laki" value={data.total_laki} />
        <StatTile label="Perempuan" value={data.total_perempuan} />
        <StatTile label="Jumlah KK" value={data.total_kk} />
        {/* Berpasangan dengan Jumlah KK dan sengaja berdampingan: angka KK sendirian
            terbaca sebagai kenyataan, padahal sisanya cuma belum didata. */}
        <StatTugas label="Belum Masuk Keluarga" value={data.belum_masuk_keluarga}
          href="/jamaah?tanpa_keluarga=1" />
        <StatTile label="Mubaligh" value={data.total_mubaligh} />
        <StatTile label="Kegiatan Bulan Ini" value={data.kegiatan_bulan_ini} />
        <StatTile label="Jamaah Tidak Aktif" value={data.total_tidak_aktif} />
      </div>

      {/* Angka wilayah jarang berubah dan tidak dipakai mengambil keputusan harian —
          tidak perlu sebesar angka orang. */}
      {wilayah.length > 0 && (
        <div className="flex flex-wrap gap-x-8 gap-y-2 rounded-xl border border-gray-200 bg-white px-4 py-3">
          {wilayah.map(([label, total]) => (
            <p key={label} className="text-sm text-gray-500">
              {label} <span className="ml-1 font-semibold text-gray-900">{total}</span>
            </p>
          ))}
        </div>
      )}

      <div className="rounded-xl border border-gray-200 bg-white p-4">
        <h3 className="mb-4 text-sm font-semibold text-gray-900">
          Jamaah Aktif per Kategori Usia
        </h3>
        <div className="space-y-2">
          {kategori.map((k) => (
            <div key={k.label} className="flex items-center gap-3">
              <span className="w-36 shrink-0 text-sm text-gray-600">
                {k.label}
              </span>
              <div className="h-5 flex-1">
                <div
                  className="h-full rounded-r bg-emerald-600"
                  style={{ width: `${(k.total / max) * 100}%`, minWidth: k.total > 0 ? 4 : 0 }}
                />
              </div>
              <span className="w-10 shrink-0 text-right text-sm font-medium text-gray-900">
                {k.total}
              </span>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
