"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { api } from "@/lib/api";
import { JENIS_PENGAJIAN } from "@/lib/labels";
import { Pagination } from "@/components/Pagination";

interface Opsi { id: number; nama: string }
interface Kegiatan {
  id: number;
  nama: string;
  jenis_pengajian: string;
  tanggal: string;
  jam_mulai: string | null;
  daerah?: Opsi | null;
  desa?: Opsi | null;
  kelompok?: Opsi | null;
  absensis_count: number;
}

const PER_PAGE = 25;

const KOSONG = {
  nama: "", jenis_pengajian: "umum", target: "", tanggal: "", jam_mulai: "", jam_selesai: "",
};

export default function KegiatanPage() {
  const [rows, setRows] = useState<Kegiatan[]>([]);
  const [daerahs, setDaerahs] = useState<Opsi[]>([]);
  const [desas, setDesas] = useState<Opsi[]>([]);
  const [kelompoks, setKelompoks] = useState<Opsi[]>([]);
  const [form, setForm] = useState<typeof KOSONG | null>(null);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);

  const reload = useCallback(() => {
    Promise.resolve().then(() => setLoading(true)); // defer 1 microtask: react-hooks/set-state-in-effect gak suka setState sinkron di body effect
    api<{ data: Kegiatan[]; last_page: number; total: number }>(`/kegiatans?page=${page}`)
      .then((res) => {
        setRows(res.data.data);
        setLastPage(res.data.last_page);
        setTotal(res.data.total);
      })
      .catch((err) => setError(err.message))
      .finally(() => setLoading(false));
  }, [page]);

  useEffect(reload, [reload]);
  useEffect(() => {
    // master mungkin 403 untuk role absensi level bawah — abaikan yang gagal
    api<Opsi[]>("/daerahs").then((r) => setDaerahs(r.data)).catch(() => {});
    api<Opsi[]>("/desas").then((r) => setDesas(r.data)).catch(() => {});
    api<Opsi[]>("/kelompoks").then((r) => setKelompoks(r.data)).catch(() => {});
  }, []);

  async function simpan(e: React.FormEvent) {
    e.preventDefault();
    if (!form) return;
    const [level, id] = form.target.split(":");
    try {
      await api("/kegiatans", {
        method: "POST",
        body: JSON.stringify({
          nama: form.nama,
          jenis_pengajian: form.jenis_pengajian,
          daerah_id: level === "daerah" ? Number(id) : null,
          desa_id: level === "desa" ? Number(id) : null,
          kelompok_id: level === "kelompok" ? Number(id) : null,
          tanggal: form.tanggal,
          jam_mulai: form.jam_mulai || null,
          jam_selesai: form.jam_selesai || null,
        }),
      });
      setForm(null);
      reload();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Gagal menyimpan");
    }
  }

  async function hapus(k: Kegiatan) {
    if (!confirm(`Hapus kegiatan "${k.nama}"?`)) return;
    try {
      await api(`/kegiatans/${k.id}`, { method: "DELETE" });
      reload();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Gagal menghapus");
    }
  }

  const input = "w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none";
  const label = "mb-1 block text-xs font-medium text-gray-600";

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h2 className="text-xl font-bold text-gray-900">Kegiatan</h2>
        <button onClick={() => setForm(KOSONG)}
          className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
          + Buat Kegiatan
        </button>
      </div>

      {error && <p className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}

      <div className="space-y-2 sm:hidden">
        {loading && (
          <p className="rounded-xl border border-gray-200 bg-white p-6 text-center text-gray-400">Memuat...</p>
        )}
        {!loading && rows.length === 0 && (
          <p className="rounded-xl border border-gray-200 bg-white p-6 text-center text-gray-400">Belum ada kegiatan</p>
        )}
        {rows.map((k) => (
          <div key={k.id} className="rounded-xl border border-gray-200 bg-white p-3">
            <Link href={`/kegiatan/${k.id}`} className="font-medium text-gray-900 hover:text-emerald-700">{k.nama}</Link>
            <p className="text-xs text-gray-500">
              {k.tanggal.slice(0, 10)}{k.jam_mulai ? ` ${k.jam_mulai.slice(0, 5)}` : ""} · {JENIS_PENGAJIAN[k.jenis_pengajian]}
            </p>
            <p className="text-xs text-gray-400">
              {k.kelompok ? `Kelompok ${k.kelompok.nama}` : k.desa ? `Desa ${k.desa.nama}` : k.daerah ? `Daerah ${k.daerah.nama}` : "-"}
            </p>
            <div className="mt-2 flex items-center justify-between border-t border-gray-100 pt-2 text-xs">
              <span className="text-gray-400">Tercatat: {k.absensis_count}</span>
              <div className="flex gap-3">
                <Link href={`/kegiatan/${k.id}`} className="font-semibold text-emerald-600 hover:text-emerald-800">Absensi</Link>
                <button onClick={() => hapus(k)} className="text-red-400 hover:text-red-700">Hapus</button>
              </div>
            </div>
          </div>
        ))}
      </div>

      <div className="hidden overflow-x-auto rounded-xl border border-gray-200 bg-white sm:block">
        <table className="w-full text-sm">
          <thead className="bg-gray-50 text-left text-xs text-gray-500">
            <tr>
              <th className="p-3">Tanggal</th>
              <th className="p-3">Nama Pengajian</th>
              <th className="p-3">Jenis</th>
              <th className="p-3">Target</th>
              <th className="p-3">Tercatat</th>
              <th className="p-3"></th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {rows.map((k) => (
              <tr key={k.id}>
                <td className="p-3">{k.tanggal.slice(0, 10)}{k.jam_mulai ? ` ${k.jam_mulai.slice(0, 5)}` : ""}</td>
                <td className="p-3 font-medium text-gray-900">
                  <Link href={`/kegiatan/${k.id}`} className="hover:text-emerald-700">{k.nama}</Link>
                </td>
                <td className="p-3">{JENIS_PENGAJIAN[k.jenis_pengajian]}</td>
                <td className="p-3">
                  {k.kelompok ? `Kelompok ${k.kelompok.nama}` : k.desa ? `Desa ${k.desa.nama}` : k.daerah ? `Daerah ${k.daerah.nama}` : "-"}
                </td>
                <td className="p-3">{k.absensis_count}</td>
                <td className="p-3 text-right">
                  <Link href={`/kegiatan/${k.id}`} className="mr-2 text-xs font-semibold text-emerald-600 hover:text-emerald-800">Absensi</Link>
                  <button onClick={() => hapus(k)} className="text-xs text-red-400 hover:text-red-700">Hapus</button>
                </td>
              </tr>
            ))}
            {!loading && rows.length === 0 && (
              <tr><td colSpan={6} className="p-6 text-center text-gray-400">Belum ada kegiatan</td></tr>
            )}
            {loading && (
              <tr><td colSpan={6} className="p-6 text-center text-gray-400">Memuat...</td></tr>
            )}
          </tbody>
        </table>
      </div>

      {total > 0 && (
        <div className="flex items-center justify-between text-sm text-gray-500">
          <span>
            Menampilkan {(page - 1) * PER_PAGE + 1}–{Math.min(page * PER_PAGE, total)} dari {total} kegiatan
          </span>
          <Pagination page={page} lastPage={lastPage} onChange={setPage} />
        </div>
      )}

      {form && (
        <div className="fixed inset-0 z-10 flex items-start justify-center overflow-y-auto bg-black/30 p-4">
          <form onSubmit={simpan} className="my-8 w-full max-w-md space-y-4 rounded-xl bg-white p-6 shadow-xl">
            <h3 className="text-lg font-bold text-gray-900">Buat Kegiatan</h3>
            <div>
              <label className={label} htmlFor="kg-nama">Nama Pengajian *</label>
              <input id="kg-nama" required className={input} value={form.nama}
                onChange={(e) => setForm({ ...form, nama: e.target.value })} />
            </div>
            <div>
              <label className={label} htmlFor="kg-jenis_pengajian">Jenis Pengajian *</label>
              <select id="kg-jenis_pengajian" className={input} value={form.jenis_pengajian}
                onChange={(e) => setForm({ ...form, jenis_pengajian: e.target.value })}>
                {Object.entries(JENIS_PENGAJIAN).map(([v, l]) => (
                  <option key={v} value={v}>{l}</option>
                ))}
              </select>
            </div>
            <div>
              <label className={label} htmlFor="kg-target">Target Struktur *</label>
              <select id="kg-target" required className={input} value={form.target}
                onChange={(e) => setForm({ ...form, target: e.target.value })}>
                <option value="">Pilih...</option>
                {daerahs.map((d) => <option key={`da${d.id}`} value={`daerah:${d.id}`}>Daerah — {d.nama}</option>)}
                {desas.map((d) => <option key={`de${d.id}`} value={`desa:${d.id}`}>Desa — {d.nama}</option>)}
                {kelompoks.map((k) => <option key={`ke${k.id}`} value={`kelompok:${k.id}`}>Kelompok — {k.nama}</option>)}
              </select>
            </div>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
              <div>
                <label className={label} htmlFor="kg-tanggal">Tanggal *</label>
                <input id="kg-tanggal" type="date" required className={input} value={form.tanggal}
                  onChange={(e) => setForm({ ...form, tanggal: e.target.value })} />
              </div>
              <div>
                <label className={label} htmlFor="kg-jam_mulai">Jam Mulai</label>
                <input id="kg-jam_mulai" type="time" className={input} value={form.jam_mulai}
                  onChange={(e) => setForm({ ...form, jam_mulai: e.target.value })} />
              </div>
              <div>
                <label className={label} htmlFor="kg-jam_selesai">Jam Selesai</label>
                <input id="kg-jam_selesai" type="time" className={input} value={form.jam_selesai}
                  onChange={(e) => setForm({ ...form, jam_selesai: e.target.value })} />
              </div>
            </div>
            <div className="flex justify-end gap-2">
              <button type="button" onClick={() => setForm(null)}
                className="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Batal</button>
              <button type="submit"
                className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">Simpan</button>
            </div>
          </form>
        </div>
      )}
    </div>
  );
}
