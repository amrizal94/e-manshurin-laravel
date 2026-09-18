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
  per_kategori_usia: Record<string, { L?: number; P?: number }>;
  kegiatan_bulan_ini: number;
}

// Slot 1 dan 2 palet kategorikal, lolos validator buta warna di atas putih.
const LAKI = "#2a78d6";
const PEREMPUAN = "#eb6834";

/**
 * Rentang bulan ini dalam waktu setempat, untuk link Kegiatan Bulan Ini. Backend
 * menghitung dalam WIB; selama browsernya juga WIB, jumlahnya sama.
 */
function bulanIni() {
  const d = new Date();
  const tgl = (x: Date) =>
    `${x.getFullYear()}-${String(x.getMonth() + 1).padStart(2, "0")}-${String(x.getDate()).padStart(2, "0")}`;

  return `dari=${tgl(new Date(d.getFullYear(), d.getMonth(), 1))}&sampai=${tgl(new Date(d.getFullYear(), d.getMonth() + 1, 0))}`;
}

/**
 * Tile dengan `href` mendarat di daftar yang jumlahnya sama persis dengan angkanya —
 * DashboardApiTest menjaga itu. Tanpa daftar yang cocok, tile-nya tetap tidak bisa diklik.
 */
function StatTile({ label, value, href }: { label: string; value: number; href?: string }) {
  const isi = (
    <>
      <p className="flex justify-between text-sm text-gray-500">
        {label}
        {/* Di HP tidak ada hover — tanpa tanda ini tidak ada yang tahu tile-nya bisa diklik. */}
        {href && <span aria-hidden="true" className="text-gray-300">›</span>}
      </p>
      <p className="mt-1 text-3xl font-bold text-gray-900">{value}</p>
    </>
  );

  return href ? (
    <Link href={href} className="rounded-xl border border-gray-200 bg-white p-4 hover:border-emerald-400">
      {isi}
    </Link>
  ) : (
    <div className="rounded-xl border border-gray-200 bg-white p-4">{isi}</div>
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
    .map(([key, label]) => {
      const L = data.per_kategori_usia[key]?.L ?? 0;
      const P = data.per_kategori_usia[key]?.P ?? 0;

      return { key, label, L, P, total: L + P };
    })
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
        <StatTile label="Jamaah Aktif" value={data.total_jamaah} href="/jamaah?aktif=1" />
        <StatTile label="Laki-laki" value={data.total_laki} href="/jamaah?aktif=1&jenis_kelamin=L" />
        <StatTile label="Perempuan" value={data.total_perempuan} href="/jamaah?aktif=1&jenis_kelamin=P" />
        {/* Tanpa link: mode Per Keluarga menghitung keluarga dengan cara lain, jadi
            daftarnya tidak akan berjumlah sama. Pekerjaannya ada di tile sebelah. */}
        <StatTile label="Jumlah KK" value={data.total_kk} />
        {/* Berpasangan dengan Jumlah KK dan sengaja berdampingan: angka KK sendirian
            terbaca sebagai kenyataan, padahal sisanya cuma belum didata. */}
        <StatTugas label="Belum Masuk Keluarga" value={data.belum_masuk_keluarga}
          href="/jamaah?tanpa_keluarga=1" />
        <StatTile label="Mubaligh" value={data.total_mubaligh} href="/jamaah?aktif=1&status_mubaligh=1" />
        <StatTile label="Kegiatan Bulan Ini" value={data.kegiatan_bulan_ini} href={`/kegiatan?${bulanIni()}`} />
        <StatTile label="Jamaah Tidak Aktif" value={data.total_tidak_aktif} href="/jamaah?aktif=0" />
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
        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
          <h3 className="text-sm font-semibold text-gray-900">Jamaah Aktif per Kategori Usia</h3>
          <div className="flex gap-4 text-xs text-gray-500">
            {([["Laki-laki", LAKI], ["Perempuan", PEREMPUAN]] as const).map(([l, warna]) => (
              <span key={l} className="flex items-center gap-1.5">
                <span className="h-2.5 w-2.5 rounded-sm" style={{ background: warna }} />
                {l}
              </span>
            ))}
          </div>
        </div>

        {/* Satu baris satu link, sengaja lebar: di HP satu segmen batang terlalu kecil
            untuk ditekan. Menyaring ke satu jenis kelamin dikerjakan di halaman tujuan.
            Di HP batangnya turun ke baris kedua supaya tidak tergencet label dan angka. */}
        <div className="space-y-0.5">
          {kategori.map((k) => (
            <Link
              key={k.key}
              href={`/jamaah?aktif=1&kategori_usia=${k.key}`}
              title={`${k.label}: ${k.L} laki-laki, ${k.P} perempuan`}
              className="grid grid-cols-[1fr_auto] items-center gap-x-3 gap-y-1.5 rounded-lg px-2 py-1.5 hover:bg-gray-50 sm:grid-cols-[9rem_1fr_auto]"
            >
              <span className="text-sm text-gray-700">{k.label}</span>
              <div aria-hidden="true" className="col-span-2 h-4 sm:col-span-1 sm:col-start-2 sm:row-start-1">
                <div
                  className="flex h-full gap-0.5 overflow-hidden rounded-r"
                  style={{ width: `${(k.total / max) * 100}%`, minWidth: k.total > 0 ? 4 : 0 }}
                >
                  {k.L > 0 && <div style={{ flexGrow: k.L, background: LAKI }} />}
                  {k.P > 0 && <div style={{ flexGrow: k.P, background: PEREMPUAN }} />}
                </div>
              </div>
              <span className="col-start-2 row-start-1 whitespace-nowrap text-right text-sm tabular-nums text-gray-500 sm:col-start-3">
                {k.L} L · {k.P} P
                <span className="ml-3 inline-block w-8 font-semibold text-gray-900">{k.total}</span>
              </span>
            </Link>
          ))}
        </div>
      </div>
    </div>
  );
}
