"use client";

import { useState } from "react";
import { api } from "@/lib/api";
import { JENIS_PENGAJIAN } from "@/lib/labels";

interface RekapKegiatan { id: number; nama: string; tanggal: string; jenis_pengajian: string }
interface RekapRow {
  jamaah: { id: number; nama_lengkap: string; kelompok: string | null; kategori_usia: string };
  statuses: Record<string, string | null>;
  perlu_perhatian: boolean;
}

const SEL: Record<string, string> = {
  hadir: "bg-emerald-50 text-emerald-700",
  izin: "bg-amber-50 text-amber-700",
  alpha: "bg-red-50 text-red-700",
};
const SINGKAT: Record<string, string> = { hadir: "H", izin: "I", alpha: "A" };

export default function RekapPage() {
  const now = new Date();
  const awalBulan = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}-01`;
  const hariIni = now.toISOString().slice(0, 10);

  const [dari, setDari] = useState(awalBulan);
  const [sampai, setSampai] = useState(hariIni);
  const [jenis, setJenis] = useState("");
  const [kegiatans, setKegiatans] = useState<RekapKegiatan[]>([]);
  const [rows, setRows] = useState<RekapRow[]>([]);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

  async function muat() {
    setLoading(true);
    setError("");
    try {
      const params = new URLSearchParams({ dari, sampai });
      if (jenis) params.set("jenis_pengajian", jenis);
      const res = await api<{ kegiatans: RekapKegiatan[]; rows: RekapRow[] }>(`/rekap?${params}`);
      setKegiatans(res.data.kegiatans);
      setRows(res.data.rows);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Gagal memuat");
    } finally {
      setLoading(false);
    }
  }

  const input = "rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none";

  return (
    <div className="space-y-4">
      <h2 className="text-xl font-bold text-gray-900">Rekapitulasi Absensi</h2>

      <div className="flex flex-wrap items-end gap-3">
        <div>
          <label className="mb-1 block text-xs font-medium text-gray-600" htmlFor="rk-dari">Dari</label>
          <input id="rk-dari" type="date" className={input} value={dari} onChange={(e) => setDari(e.target.value)} />
        </div>
        <div>
          <label className="mb-1 block text-xs font-medium text-gray-600" htmlFor="rk-sampai">Sampai</label>
          <input id="rk-sampai" type="date" className={input} value={sampai} onChange={(e) => setSampai(e.target.value)} />
        </div>
        <div>
          <label className="mb-1 block text-xs font-medium text-gray-600" htmlFor="rk-jenis">Jenis Pengajian</label>
          <select id="rk-jenis" className={input} value={jenis} onChange={(e) => setJenis(e.target.value)}>
            <option value="">Semua</option>
            {Object.entries(JENIS_PENGAJIAN).map(([v, l]) => (
              <option key={v} value={v}>{l}</option>
            ))}
          </select>
        </div>
        <button onClick={muat} disabled={loading}
          className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50">
          {loading ? "Memuat..." : "Tampilkan"}
        </button>
      </div>

      {error && <p className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}

      {kegiatans.length > 0 && (
        <>
          <p className="text-xs text-gray-500">
            H = Hadir · I = Izin · A = Alpha · <span className="font-semibold text-red-600">★</span> = 3+ alpha berturut-turut
          </p>
          <div className="overflow-x-auto rounded-xl border border-gray-200 bg-white">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 text-left text-xs text-gray-500">
                <tr>
                  <th className="sticky left-0 bg-gray-50 p-3">Nama</th>
                  <th className="p-3">Kelompok</th>
                  {kegiatans.map((k) => (
                    <th key={k.id} className="p-3 text-center" title={k.nama}>
                      {k.tanggal.slice(5)}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {rows.map((r) => (
                  <tr key={r.jamaah.id} className={r.perlu_perhatian ? "bg-red-50/50" : ""}>
                    <td className="sticky left-0 bg-inherit p-3 font-medium text-gray-900">
                      {r.perlu_perhatian && <span className="mr-1 text-red-600">★</span>}
                      {r.jamaah.nama_lengkap}
                    </td>
                    <td className="p-3 text-gray-500">{r.jamaah.kelompok}</td>
                    {kegiatans.map((k) => {
                      const s = r.statuses[k.id];
                      return (
                        <td key={k.id} className="p-1 text-center">
                          {s ? (
                            <span className={`inline-block w-6 rounded py-0.5 text-xs font-semibold ${SEL[s]}`}>
                              {SINGKAT[s]}
                            </span>
                          ) : (
                            <span className="text-gray-300">·</span>
                          )}
                        </td>
                      );
                    })}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}

      {!loading && kegiatans.length === 0 && (
        <p className="text-sm text-gray-400">Pilih rentang tanggal lalu klik Tampilkan.</p>
      )}
    </div>
  );
}
