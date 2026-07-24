"use client";

import { useCallback, useEffect, useState } from "react";
import { api } from "@/lib/api";
import { ROLE_LABEL } from "@/lib/labels";

interface Opsi { id: number; nama: string }
interface Pengguna {
  id: number;
  name: string;
  email: string;
  roles: { name: string }[];
  daerah?: Opsi | null;
  desa?: Opsi | null;
  kelompok?: Opsi | null;
}

const KOSONG = { name: "", email: "", password: "", role: "absensi", target: "semua" };

export default function PenggunaPage() {
  const [rows, setRows] = useState<Pengguna[]>([]);
  const [daerahs, setDaerahs] = useState<Opsi[]>([]);
  const [desas, setDesas] = useState<Opsi[]>([]);
  const [kelompoks, setKelompoks] = useState<Opsi[]>([]);
  const [form, setForm] = useState<typeof KOSONG | null>(null);
  const [editId, setEditId] = useState<number | null>(null);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(true);
  const [lihatPassword, setLihatPassword] = useState(false);

  const reload = useCallback(() => {
    Promise.resolve().then(() => setLoading(true)); // defer 1 microtask: react-hooks/set-state-in-effect gak suka setState sinkron di body effect
    api<Pengguna[]>("/users")
      .then((res) => setRows(res.data))
      .catch((err) => setError(err.message))
      .finally(() => setLoading(false));
  }, []);

  useEffect(reload, [reload]);
  useEffect(() => {
    api<Opsi[]>("/daerahs").then((r) => setDaerahs(r.data)).catch(() => {});
    api<Opsi[]>("/desas").then((r) => setDesas(r.data)).catch(() => {});
    api<Opsi[]>("/kelompoks").then((r) => setKelompoks(r.data)).catch(() => {});
  }, []);

  function targetDari(u: Pengguna): string {
    if (u.kelompok) return `kelompok:${u.kelompok.id}`;
    if (u.desa) return `desa:${u.desa.id}`;
    if (u.daerah) return `daerah:${u.daerah.id}`;
    return "semua";
  }

  function buka(u?: Pengguna) {
    setError("");
    setEditId(u?.id ?? null);
    setLihatPassword(false);
    setForm(u ? {
      name: u.name, email: u.email, password: "",
      role: u.roles[0]?.name ?? "absensi", target: targetDari(u),
    } : { ...KOSONG });
  }

  async function simpan(e: React.FormEvent) {
    e.preventDefault();
    if (!form) return;
    setError("");
    const [level, id] = form.target.split(":");
    const body = JSON.stringify({
      name: form.name,
      email: form.email,
      ...(form.password ? { password: form.password } : {}),
      role: form.role,
      daerah_id: level === "daerah" ? Number(id) : null,
      desa_id: level === "desa" ? Number(id) : null,
      kelompok_id: level === "kelompok" ? Number(id) : null,
    });
    try {
      await api(editId ? `/users/${editId}` : "/users", {
        method: editId ? "PUT" : "POST",
        body,
      });
      setForm(null);
      reload();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Gagal menyimpan");
    }
  }

  async function hapus(u: Pengguna) {
    if (!confirm(`Hapus pengguna "${u.name}"?`)) return;
    try {
      await api(`/users/${u.id}`, { method: "DELETE" });
      reload();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Gagal menghapus");
    }
  }

  const input = "w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 focus:border-emerald-500 focus:outline-none";
  const label = "mb-1 block text-xs font-medium text-gray-600";

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h2 className="text-xl font-bold text-gray-900">Pengguna</h2>
        <button onClick={() => buka()}
          className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
          + Tambah Pengguna
        </button>
      </div>

      {error && <p className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}

      <div className="space-y-2 sm:hidden">
        {loading && (
          <p className="rounded-xl border border-gray-200 bg-white p-6 text-center text-gray-400">Memuat...</p>
        )}
        {!loading && rows.length === 0 && (
          <p className="rounded-xl border border-gray-200 bg-white p-6 text-center text-gray-400">Belum ada data</p>
        )}
        {rows.map((u) => (
          <div key={u.id} className="rounded-xl border border-gray-200 bg-white p-3">
            <div className="flex items-start justify-between gap-2">
              <div className="min-w-0">
                <p className="truncate font-medium text-gray-900">{u.name}</p>
                <p className="truncate text-xs text-gray-500">{u.email}</p>
              </div>
              <span className="shrink-0 rounded bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700">
                {ROLE_LABEL[u.roles[0]?.name] ?? u.roles[0]?.name ?? "-"}
              </span>
            </div>
            <p className="mt-1 text-xs text-gray-400">
              {u.kelompok ? `Kelompok ${u.kelompok.nama}` : u.desa ? `Desa ${u.desa.nama}` : u.daerah ? `Daerah ${u.daerah.nama}` : "Seluruh"}
            </p>
            <div className="mt-2 flex justify-end gap-3 border-t border-gray-100 pt-2 text-xs">
              <button onClick={() => buka(u)} className="text-gray-400 hover:text-gray-700">Edit</button>
              <button onClick={() => hapus(u)} className="text-red-400 hover:text-red-700">Hapus</button>
            </div>
          </div>
        ))}
      </div>

      <div className="hidden overflow-x-auto rounded-xl border border-gray-200 bg-white sm:block">
        <table className="w-full text-sm">
          <thead className="bg-gray-50 text-left text-xs text-gray-500">
            <tr>
              <th className="p-3">Nama</th>
              <th className="p-3">Email</th>
              <th className="p-3">Peran</th>
              <th className="p-3">Wilayah</th>
              <th className="p-3"></th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {rows.map((u) => (
              <tr key={u.id}>
                <td className="p-3 font-medium text-gray-900">{u.name}</td>
                <td className="p-3">{u.email}</td>
                <td className="p-3">
                  <span className="rounded bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700">
                    {ROLE_LABEL[u.roles[0]?.name] ?? u.roles[0]?.name ?? "-"}
                  </span>
                </td>
                <td className="p-3">
                  {u.kelompok ? `Kelompok ${u.kelompok.nama}` : u.desa ? `Desa ${u.desa.nama}` : u.daerah ? `Daerah ${u.daerah.nama}` : "Seluruh"}
                </td>
                <td className="p-3 text-right">
                  <button onClick={() => buka(u)} className="mr-2 text-xs text-gray-400 hover:text-gray-700">Edit</button>
                  <button onClick={() => hapus(u)} className="text-xs text-red-400 hover:text-red-700">Hapus</button>
                </td>
              </tr>
            ))}
            {!loading && rows.length === 0 && (
              <tr><td colSpan={5} className="p-6 text-center text-gray-400">Belum ada data</td></tr>
            )}
            {loading && (
              <tr><td colSpan={5} className="p-6 text-center text-gray-400">Memuat...</td></tr>
            )}
          </tbody>
        </table>
      </div>

      {form && (
        <div className="fixed inset-0 z-10 flex items-start justify-center overflow-y-auto bg-black/30 p-4">
          <form onSubmit={simpan} className="my-8 w-full max-w-md space-y-4 rounded-xl bg-white p-6 shadow-xl">
            <h3 className="text-lg font-bold text-gray-900">{editId ? "Edit" : "Tambah"} Pengguna</h3>
            <div>
              <label className={label} htmlFor="pg-name">Nama *</label>
              <input id="pg-name" required className={input} value={form.name}
                onChange={(e) => setForm({ ...form, name: e.target.value })} />
            </div>
            <div>
              <label className={label} htmlFor="pg-email">Email *</label>
              <input id="pg-email" type="email" required className={input} value={form.email}
                onChange={(e) => setForm({ ...form, email: e.target.value })} />
            </div>
            <div>
              <label className={label} htmlFor="pg-password">Password {editId ? "(kosongkan jika tidak diubah)" : "*"}</label>
              <div className="relative">
                <input id="pg-password" type={lihatPassword ? "text" : "password"} required={!editId} minLength={8}
                  className={`${input} pr-10`} value={form.password}
                  onChange={(e) => setForm({ ...form, password: e.target.value })} />
                <button
                  type="button"
                  onClick={() => setLihatPassword((v) => !v)}
                  className="absolute inset-y-0 right-0 flex items-center px-3 text-gray-400 hover:text-gray-600"
                  aria-label={lihatPassword ? "Sembunyikan password" : "Tampilkan password"}
                >
                  {lihatPassword ? (
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-4 w-4">
                      <path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a20.3 20.3 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a20.28 20.28 0 0 1-3.22 4.44M14.12 14.12a3 3 0 1 1-4.24-4.24" />
                      <line x1="1" y1="1" x2="23" y2="23" />
                    </svg>
                  ) : (
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-4 w-4">
                      <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z" />
                      <circle cx="12" cy="12" r="3" />
                    </svg>
                  )}
                </button>
              </div>
            </div>
            <div>
              <label className={label} htmlFor="pg-role">Peran *</label>
              <select id="pg-role" className={input} value={form.role}
                onChange={(e) => setForm({ ...form, role: e.target.value })}>
                {Object.entries(ROLE_LABEL).map(([v, l]) => (
                  <option key={v} value={v}>{l}</option>
                ))}
              </select>
            </div>
            <div>
              <label className={label} htmlFor="pg-target">Wilayah *</label>
              <select id="pg-target" required className={input} value={form.target}
                onChange={(e) => setForm({ ...form, target: e.target.value })}>
                <option value="semua">Seluruh (Super Admin)</option>
                {daerahs.map((d) => <option key={`da${d.id}`} value={`daerah:${d.id}`}>Daerah — {d.nama}</option>)}
                {desas.map((d) => <option key={`de${d.id}`} value={`desa:${d.id}`}>Desa — {d.nama}</option>)}
                {kelompoks.map((k) => <option key={`ke${k.id}`} value={`kelompok:${k.id}`}>Kelompok — {k.nama}</option>)}
              </select>
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
