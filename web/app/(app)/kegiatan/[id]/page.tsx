"use client";

import { useCallback, useEffect, useState } from "react";
import { useParams } from "next/navigation";
import Link from "next/link";
import { api } from "@/lib/api";
import { JENIS_PENGAJIAN, KATEGORI_USIA } from "@/lib/labels";

interface Kegiatan {
  id: number;
  nama: string;
  jenis_pengajian: string;
  tanggal: string;
}
interface Peserta {
  id: number;
  nama_lengkap: string;
  kategori_usia: string;
  kelompok?: { nama: string };
  absensi: { status: string; keterangan: string | null; metode: string } | null;
}

export default function KegiatanDetailPage() {
  const { id } = useParams<{ id: string }>();
  const [kegiatan, setKegiatan] = useState<Kegiatan | null>(null);
  const [peserta, setPeserta] = useState<Peserta[]>([]);
  const [error, setError] = useState("");

  const reload = useCallback(() => {
    api<Kegiatan>(`/kegiatans/${id}`).then((r) => setKegiatan(r.data)).catch((e) => setError(e.message));
    api<Peserta[]>(`/kegiatans/${id}/peserta`).then((r) => setPeserta(r.data)).catch((e) => setError(e.message));
  }, [id]);

  useEffect(reload, [reload]);

  async function tandai(jamaahId: number, status: string) {
    let keterangan: string | null = null;
    if (status === "izin") {
      keterangan = prompt("Keterangan izin:");
      if (keterangan === null) return;
    }
    try {
      await api(`/kegiatans/${id}/absensi`, {
        method: "POST",
        body: JSON.stringify({ jamaah_id: jamaahId, status, keterangan }),
      });
      reload();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Gagal");
    }
  }

  const hadir = peserta.filter((p) => p.absensi?.status === "hadir").length;
  const izin = peserta.filter((p) => p.absensi?.status === "izin").length;
  const belum = peserta.length - hadir - izin - peserta.filter((p) => p.absensi?.status === "alpha").length;

  const btn = (aktif: boolean, warna: string) =>
    `rounded px-2 py-1 text-xs font-semibold ${aktif ? warna : "bg-gray-100 text-gray-500 hover:bg-gray-200"}`;

  return (
    <div className="space-y-4">
      {error && <p className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}

      {kegiatan && (
        <div className="flex items-start justify-between">
          <div>
            <h2 className="text-xl font-bold text-gray-900">{kegiatan.nama}</h2>
            <p className="text-sm text-gray-500">
              {JENIS_PENGAJIAN[kegiatan.jenis_pengajian]} · {kegiatan.tanggal.slice(0, 10)} ·{" "}
              {peserta.length} peserta · {hadir} hadir · {izin} izin · {belum} belum tercatat
            </p>
          </div>
          <Link
            href={`/kegiatan/${kegiatan.id}/absen-wajah`}
            className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700"
          >
            📷 Absen Wajah
          </Link>
        </div>
      )}

      <div className="overflow-x-auto rounded-xl border border-gray-200 bg-white">
        <table className="w-full text-sm">
          <thead className="bg-gray-50 text-left text-xs text-gray-500">
            <tr>
              <th className="p-3">Nama</th>
              <th className="p-3">Kategori</th>
              <th className="p-3">Kelompok</th>
              <th className="p-3">Status</th>
              <th className="p-3">Tandai</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {peserta.map((p) => (
              <tr key={p.id}>
                <td className="p-3 font-medium text-gray-900">{p.nama_lengkap}</td>
                <td className="p-3">{KATEGORI_USIA[p.kategori_usia]}</td>
                <td className="p-3">{p.kelompok?.nama}</td>
                <td className="p-3">
                  {p.absensi ? (
                    <span
                      title={p.absensi.keterangan ?? ""}
                      className={
                        p.absensi.status === "hadir"
                          ? "rounded bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700"
                          : p.absensi.status === "izin"
                            ? "rounded bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700"
                            : "rounded bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700"
                      }
                    >
                      {p.absensi.status}{p.absensi.keterangan ? ` — ${p.absensi.keterangan}` : ""}
                    </span>
                  ) : (
                    <span className="text-xs text-gray-400">belum tercatat</span>
                  )}
                </td>
                <td className="p-3">
                  <div className="flex gap-1">
                    <button onClick={() => tandai(p.id, "hadir")}
                      className={btn(p.absensi?.status === "hadir", "bg-emerald-600 text-white")}>Hadir</button>
                    <button onClick={() => tandai(p.id, "izin")}
                      className={btn(p.absensi?.status === "izin", "bg-amber-500 text-white")}>Izin</button>
                    <button onClick={() => tandai(p.id, "alpha")}
                      className={btn(p.absensi?.status === "alpha", "bg-red-600 text-white")}>Alpha</button>
                  </div>
                </td>
              </tr>
            ))}
            {peserta.length === 0 && (
              <tr><td colSpan={5} className="p-6 text-center text-gray-400">Tidak ada peserta</td></tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
