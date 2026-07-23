"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { api } from "@/lib/api";
import { KATEGORI_USIA } from "@/lib/labels";

interface Kelompok { id: number; nama: string; desa?: { nama: string } }
interface Jamaah {
  id: number;
  nama_lengkap: string;
  nama_panggilan: string | null;
  jenis_kelamin: "L" | "P";
  tempat_lahir: string | null;
  tanggal_lahir: string | null;
  usia: number | null;
  alamat: string | null;
  no_hp: string | null;
  kelompok_id: number;
  kelompok?: { nama: string; desa?: { nama: string } };
  kategori_usia: string;
  pekerjaan: string | null;
  status_mubaligh: boolean;
  status_kk: string | null;
  kepala_keluarga_id: number | null;
  aktif: boolean;
  keterangan_tidak_aktif: string | null;
  photos_count?: number;
}

const KOSONG = {
  nama_lengkap: "", nama_panggilan: "", jenis_kelamin: "L", tempat_lahir: "",
  tanggal_lahir: "", alamat: "", no_hp: "", kelompok_id: 0, kategori_usia: "remaja",
  pekerjaan: "", status_mubaligh: false, status_kk: "",
  kepala_keluarga_id: "" as number | "", aktif: true, keterangan_tidak_aktif: "",
};

export default function JamaahPage() {
  const [rows, setRows] = useState<Jamaah[]>([]);
  const [kelompoks, setKelompoks] = useState<Kelompok[]>([]);
  const [search, setSearch] = useState("");
  const [searchDebounced, setSearchDebounced] = useState("");
  const [error, setError] = useState("");
  const [form, setForm] = useState<typeof KOSONG | null>(null);
  const [editId, setEditId] = useState<number | null>(null);

  useEffect(() => {
    const t = setTimeout(() => setSearchDebounced(search), 300);
    return () => clearTimeout(t);
  }, [search]);

  const reload = useCallback(() => {
    const params = searchDebounced ? `?search=${encodeURIComponent(searchDebounced)}` : "";
    api<{ data: Jamaah[] }>(`/jamaahs${params}`)
      .then((res) => setRows(res.data.data))
      .catch((err) => setError(err.message));
  }, [searchDebounced]);

  useEffect(reload, [reload]);
  useEffect(() => {
    api<Kelompok[]>("/kelompoks").then((res) => setKelompoks(res.data)).catch(() => {});
  }, []);

  function buka(j?: Jamaah) {
    setEditId(j?.id ?? null);
    setForm(j ? {
      nama_lengkap: j.nama_lengkap, nama_panggilan: j.nama_panggilan ?? "",
      jenis_kelamin: j.jenis_kelamin, tempat_lahir: j.tempat_lahir ?? "",
      tanggal_lahir: j.tanggal_lahir?.slice(0, 10) ?? "", alamat: j.alamat ?? "",
      no_hp: j.no_hp ?? "", kelompok_id: j.kelompok_id, kategori_usia: j.kategori_usia,
      pekerjaan: j.pekerjaan ?? "", status_mubaligh: j.status_mubaligh,
      status_kk: j.status_kk ?? "",
      kepala_keluarga_id: j.kepala_keluarga_id ?? "",
      aktif: j.aktif, keterangan_tidak_aktif: j.keterangan_tidak_aktif ?? "",
    } : { ...KOSONG, kelompok_id: kelompoks[0]?.id ?? 0 });
  }

  async function simpan(e: React.FormEvent) {
    e.preventDefault();
    if (!form) return;
    setError("");
    const body = JSON.stringify({
      ...form,
      nama_panggilan: form.nama_panggilan || null,
      tempat_lahir: form.tempat_lahir || null,
      tanggal_lahir: form.tanggal_lahir || null,
      alamat: form.alamat || null,
      no_hp: form.no_hp || null,
      pekerjaan: form.pekerjaan || null,
      status_kk: form.status_kk || null,
      kepala_keluarga_id: form.kepala_keluarga_id || null,
      keterangan_tidak_aktif: form.keterangan_tidak_aktif || null,
    });
    try {
      await api(editId ? `/jamaahs/${editId}` : "/jamaahs", {
        method: editId ? "PUT" : "POST",
        body,
      });
      setForm(null);
      reload();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Gagal menyimpan");
    }
  }

  async function hapus(j: Jamaah) {
    if (!confirm(`Hapus jamaah "${j.nama_lengkap}"?`)) return;
    try {
      await api(`/jamaahs/${j.id}`, { method: "DELETE" });
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
        <h2 className="text-xl font-bold text-gray-900">Data Jamaah</h2>
        <button
          onClick={() => buka()}
          className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700"
        >+ Tambah Jamaah</button>
      </div>

      {error && <p className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}

      <input
        placeholder="Cari nama lengkap/panggilan..."
        value={search}
        onChange={(e) => setSearch(e.target.value)}
        className="w-64 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
      />

      <div className="overflow-x-auto rounded-xl border border-gray-200 bg-white">
        <table className="w-full text-sm">
          <thead className="bg-gray-50 text-left text-xs text-gray-500">
            <tr>
              <th className="p-3">Nama</th>
              <th className="p-3">L/P</th>
              <th className="p-3">Usia</th>
              <th className="p-3">Kategori</th>
              <th className="p-3">Kelompok</th>
              <th className="p-3">Foto</th>
              <th className="p-3">Status</th>
              <th className="p-3"></th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {rows.map((j) => (
              <tr key={j.id}>
                <td className="p-3 font-medium text-gray-900">{j.nama_lengkap}</td>
                <td className="p-3">{j.jenis_kelamin}</td>
                <td className="p-3">{j.usia ?? "-"}</td>
                <td className="p-3">{KATEGORI_USIA[j.kategori_usia]}</td>
                <td className="p-3">
                  {j.kelompok?.nama}
                  {j.kelompok?.desa && <span className="text-gray-400"> — {j.kelompok.desa.nama}</span>}
                </td>
                <td className="p-3">{j.photos_count ?? 0}</td>
                <td className="p-3">
                  {j.aktif ? (
                    <span className="rounded bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700">Aktif</span>
                  ) : (
                    <span className="rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-500" title={j.keterangan_tidak_aktif ?? ""}>Tidak Aktif</span>
                  )}
                </td>
                <td className="p-3 text-right">
                  <Link href={`/jamaah/${j.id}/wajah`} className="mr-2 text-xs font-semibold text-emerald-600 hover:text-emerald-800">Wajah</Link>
                  <button onClick={() => buka(j)} className="mr-2 text-xs text-gray-400 hover:text-gray-700">Edit</button>
                  <button onClick={() => hapus(j)} className="text-xs text-red-400 hover:text-red-700">Hapus</button>
                </td>
              </tr>
            ))}
            {rows.length === 0 && (
              <tr><td colSpan={8} className="p-6 text-center text-gray-400">Belum ada data</td></tr>
            )}
          </tbody>
        </table>
      </div>

      {form && (
        <div className="fixed inset-0 z-10 flex items-start justify-center overflow-y-auto bg-black/30 p-4">
          <form onSubmit={simpan} className="my-8 w-full max-w-2xl space-y-4 rounded-xl bg-white p-6 shadow-xl">
            <h3 className="text-lg font-bold text-gray-900">{editId ? "Edit" : "Tambah"} Jamaah</h3>

            <div className="grid grid-cols-2 gap-4">
              <div className="col-span-2">
                <label className={label}>Nama Lengkap *</label>
                <input required className={input} value={form.nama_lengkap}
                  onChange={(e) => setForm({ ...form, nama_lengkap: e.target.value })} />
              </div>
              <div>
                <label className={label}>Nama Panggilan</label>
                <input className={input} value={form.nama_panggilan}
                  onChange={(e) => setForm({ ...form, nama_panggilan: e.target.value })} />
              </div>
              <div>
                <label className={label}>Jenis Kelamin *</label>
                <select className={input} value={form.jenis_kelamin}
                  onChange={(e) => setForm({ ...form, jenis_kelamin: e.target.value })}>
                  <option value="L">Laki-laki</option>
                  <option value="P">Perempuan</option>
                </select>
              </div>
              <div>
                <label className={label}>Tempat Lahir</label>
                <input className={input} value={form.tempat_lahir}
                  onChange={(e) => setForm({ ...form, tempat_lahir: e.target.value })} />
              </div>
              <div>
                <label className={label}>Tanggal Lahir</label>
                <input type="date" className={input} value={form.tanggal_lahir}
                  onChange={(e) => setForm({ ...form, tanggal_lahir: e.target.value })} />
              </div>
              <div className="col-span-2">
                <label className={label}>Alamat</label>
                <textarea className={input} rows={2} value={form.alamat}
                  onChange={(e) => setForm({ ...form, alamat: e.target.value })} />
              </div>
              <div>
                <label className={label}>No. HP</label>
                <input className={input} value={form.no_hp}
                  onChange={(e) => setForm({ ...form, no_hp: e.target.value })} />
              </div>
              <div>
                <label className={label}>Kelompok *</label>
                <select required className={input} value={form.kelompok_id}
                  onChange={(e) => setForm({ ...form, kelompok_id: Number(e.target.value) })}>
                  {kelompoks.map((k) => (
                    <option key={k.id} value={k.id}>{k.nama}{k.desa ? ` — ${k.desa.nama}` : ""}</option>
                  ))}
                </select>
              </div>
              <div>
                <label className={label}>Kategori Usia *</label>
                <select className={input} value={form.kategori_usia}
                  onChange={(e) => setForm({ ...form, kategori_usia: e.target.value })}>
                  {Object.entries(KATEGORI_USIA).map(([v, l]) => (
                    <option key={v} value={v}>{l}</option>
                  ))}
                </select>
              </div>
              <div>
                <label className={label}>Pekerjaan</label>
                <input className={input} value={form.pekerjaan}
                  onChange={(e) => setForm({ ...form, pekerjaan: e.target.value })} />
              </div>
              <div>
                <label className={label}>Status KK</label>
                <select className={input} value={form.status_kk}
                  onChange={(e) => setForm({
                    ...form,
                    status_kk: e.target.value,
                    kepala_keluarga_id: e.target.value === "kepala_keluarga" ? "" : form.kepala_keluarga_id,
                  })}>
                  <option value="">-</option>
                  <option value="kepala_keluarga">Kepala Keluarga</option>
                  <option value="suami">Suami</option>
                  <option value="istri">Istri</option>
                  <option value="anak">Anak</option>
                  <option value="menantu">Menantu</option>
                  <option value="cucu">Cucu</option>
                  <option value="orang_tua">Orang Tua</option>
                  <option value="mertua">Mertua</option>
                </select>
              </div>
              <div>
                <label className={label}>Kepala Keluarga</label>
                <select className={input} value={form.kepala_keluarga_id}
                  disabled={form.status_kk === "kepala_keluarga"}
                  onChange={(e) => setForm({ ...form, kepala_keluarga_id: e.target.value ? Number(e.target.value) : "" })}>
                  <option value="">- (bukan anggota keluarga siapa pun)</option>
                  {rows.filter((r) => r.id !== editId && r.status_kk === "kepala_keluarga").map((r) => (
                    <option key={r.id} value={r.id}>{r.nama_lengkap}</option>
                  ))}
                </select>
                {form.status_kk === "kepala_keluarga" && (
                  <p className="mt-1 text-xs text-gray-400">Kepala keluarga tidak bisa jadi anggota keluarga lain</p>
                )}
              </div>
              <div className="col-span-2 flex gap-6">
                <label className="flex items-center gap-2 text-sm text-gray-700">
                  <input type="checkbox" checked={form.status_mubaligh}
                    onChange={(e) => setForm({ ...form, status_mubaligh: e.target.checked })} />
                  Mubaligh
                </label>
                <label className="flex items-center gap-2 text-sm text-gray-700">
                  <input type="checkbox" checked={form.aktif}
                    onChange={(e) => setForm({ ...form, aktif: e.target.checked })} />
                  Aktif
                </label>
              </div>
              {!form.aktif && (
                <div className="col-span-2">
                  <label className={label}>Keterangan Tidak Aktif</label>
                  <input className={input} value={form.keterangan_tidak_aktif}
                    onChange={(e) => setForm({ ...form, keterangan_tidak_aktif: e.target.value })} />
                </div>
              )}
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
