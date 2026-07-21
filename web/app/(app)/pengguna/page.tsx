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

const KOSONG = { name: "", email: "", password: "", role: "absensi", target: "" };

export default function PenggunaPage() {
  const [rows, setRows] = useState<Pengguna[]>([]);
  const [daerahs, setDaerahs] = useState<Opsi[]>([]);
  const [desas, setDesas] = useState<Opsi[]>([]);
  const [kelompoks, setKelompoks] = useState<Opsi[]>([]);
  const [form, setForm] = useState<typeof KOSONG | null>(null);
  const [editId, setEditId] = useState<number | null>(null);
  const [error, setError] = useState("");

  const reload = useCallback(() => {
    api<Pengguna[]>("/users")
      .then((res) => setRows(res.data))
      .catch((err) => setError(err.message));
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
    return "";
  }

  function buka(u?: Pengguna) {
    setError("");
    setEditId(u?.id ?? null);
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

      <div className="overflow-x-auto rounded-xl border border-gray-200 bg-white">
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
            {rows.length === 0 && (
              <tr><td colSpan={5} className="p-6 text-center text-gray-400">Belum ada data</td></tr>
            )}
          </tbody>
        </table>
      </div>

      {form && (
        <div className="fixed inset-0 z-10 flex items-start justify-center overflow-y-auto bg-black/30 p-4">
          <form onSubmit={simpan} className="my-8 w-full max-w-md space-y-4 rounded-xl bg-white p-6 shadow-xl">
            <h3 className="text-lg font-bold text-gray-900">{editId ? "Edit" : "Tambah"} Pengguna</h3>
            <div>
              <label className={label}>Nama *</label>
              <input required className={input} value={form.name}
                onChange={(e) => setForm({ ...form, name: e.target.value })} />
            </div>
            <div>
              <label className={label}>Email *</label>
              <input type="email" required className={input} value={form.email}
                onChange={(e) => setForm({ ...form, email: e.target.value })} />
            </div>
            <div>
              <label className={label}>Password {editId ? "(kosongkan jika tidak diubah)" : "*"}</label>
              <input type="password" required={!editId} minLength={8} className={input} value={form.password}
                onChange={(e) => setForm({ ...form, password: e.target.value })} />
            </div>
            <div>
              <label className={label}>Peran *</label>
              <select className={input} value={form.role}
                onChange={(e) => setForm({ ...form, role: e.target.value })}>
                {Object.entries(ROLE_LABEL).map(([v, l]) => (
                  <option key={v} value={v}>{l}</option>
                ))}
              </select>
            </div>
            <div>
              <label className={label}>Wilayah *</label>
              <select required className={input} value={form.target}
                onChange={(e) => setForm({ ...form, target: e.target.value })}>
                <option value="">Seluruh (Super Admin)</option>
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
