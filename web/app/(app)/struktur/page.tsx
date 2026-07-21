"use client";

import { useCallback, useEffect, useState } from "react";
import { api } from "@/lib/api";

interface Daerah { id: number; nama: string; desas_count: number }
interface Desa { id: number; nama: string; daerah_id: number; kelompoks_count: number; daerah?: { nama: string } }
interface Kelompok { id: number; nama: string; desa_id: number; desa?: { nama: string } }

export default function StrukturPage() {
  const [daerahs, setDaerahs] = useState<Daerah[]>([]);
  const [desas, setDesas] = useState<Desa[]>([]);
  const [kelompoks, setKelompoks] = useState<Kelompok[]>([]);
  const [error, setError] = useState("");

  const reload = useCallback(() => {
    setError("");
    Promise.all([
      api<Daerah[]>("/daerahs"),
      api<Desa[]>("/desas"),
      api<Kelompok[]>("/kelompoks"),
    ])
      .then(([a, b, c]) => {
        setDaerahs(a.data);
        setDesas(b.data);
        setKelompoks(c.data);
      })
      .catch((err) => setError(err.message));
  }, []);

  useEffect(reload, [reload]);

  async function run(fn: () => Promise<unknown>) {
    setError("");
    try {
      await fn();
      reload();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Gagal");
    }
  }

  function tambahDaerah() {
    const nama = prompt("Nama daerah baru:");
    if (nama) run(() => api("/daerahs", { method: "POST", body: JSON.stringify({ nama }) }));
  }

  function tambahDesa() {
    if (!daerahs.length) return alert("Buat daerah dulu");
    const nama = prompt("Nama desa baru:");
    if (!nama) return;
    const daerahId = pilih("Pilih daerah:", daerahs.map((d) => [d.id, d.nama]));
    if (daerahId) run(() => api("/desas", { method: "POST", body: JSON.stringify({ nama, daerah_id: daerahId }) }));
  }

  function tambahKelompok() {
    if (!desas.length) return alert("Buat desa dulu");
    const nama = prompt("Nama kelompok baru:");
    if (!nama) return;
    const desaId = pilih("Pilih desa:", desas.map((d) => [d.id, d.nama]));
    if (desaId) run(() => api("/kelompoks", { method: "POST", body: JSON.stringify({ nama, desa_id: desaId }) }));
  }

  // ponytail: prompt-number picker; ganti dialog dropdown kalau daftar sudah panjang
  function pilih(title: string, options: [number, string][]): number | null {
    const teks = options.map(([id, nama], i) => `${i + 1}. ${nama}`).join("\n");
    const jawab = prompt(`${title}\n${teks}\n\nKetik nomor:`);
    const idx = jawab ? parseInt(jawab, 10) - 1 : -1;
    return options[idx]?.[0] ?? null;
  }

  function edit(path: string, current: string) {
    const nama = prompt("Nama baru:", current);
    return nama && nama !== current
      ? { nama }
      : null;
  }

  const kolom = "rounded-xl border border-gray-200 bg-white";
  const header = "flex items-center justify-between border-b border-gray-200 p-3";
  const btnTambah = "rounded bg-emerald-600 px-2 py-1 text-xs font-semibold text-white hover:bg-emerald-700";
  const baris = "flex items-center justify-between px-3 py-2 text-sm";
  const aksi = "text-xs text-gray-400 hover:text-gray-700";

  return (
    <div className="space-y-4">
      <h2 className="text-xl font-bold text-gray-900">Struktur Organisasi</h2>
      {error && <p className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}

      <div className="grid gap-4 lg:grid-cols-3">
        <section className={kolom}>
          <div className={header}>
            <h3 className="text-sm font-semibold">Daerah</h3>
            <button onClick={tambahDaerah} className={btnTambah}>+ Tambah</button>
          </div>
          <ul className="divide-y divide-gray-100">
            {daerahs.map((d) => (
              <li key={d.id} className={baris}>
                <span>{d.nama} <span className="text-gray-400">({d.desas_count} desa)</span></span>
                <span className="flex gap-2">
                  <button
                    className={aksi}
                    onClick={() => {
                      const data = edit(`/daerahs/${d.id}`, d.nama);
                      if (data) run(() => api(`/daerahs/${d.id}`, { method: "PUT", body: JSON.stringify(data) }));
                    }}
                  >Edit</button>
                  <button
                    className="text-xs text-red-400 hover:text-red-700"
                    onClick={() => {
                      if (confirm(`Hapus daerah "${d.nama}" beserta seluruh isinya?`))
                        run(() => api(`/daerahs/${d.id}`, { method: "DELETE" }));
                    }}
                  >Hapus</button>
                </span>
              </li>
            ))}
          </ul>
        </section>

        <section className={kolom}>
          <div className={header}>
            <h3 className="text-sm font-semibold">Desa</h3>
            <button onClick={tambahDesa} className={btnTambah}>+ Tambah</button>
          </div>
          <ul className="divide-y divide-gray-100">
            {desas.map((d) => (
              <li key={d.id} className={baris}>
                <span>{d.nama} <span className="text-gray-400">({d.kelompoks_count} kelompok)</span></span>
                <span className="flex gap-2">
                  <button
                    className={aksi}
                    onClick={() => {
                      const data = edit(`/desas/${d.id}`, d.nama);
                      if (data) run(() => api(`/desas/${d.id}`, { method: "PUT", body: JSON.stringify({ ...data, daerah_id: d.daerah_id }) }));
                    }}
                  >Edit</button>
                  <button
                    className="text-xs text-red-400 hover:text-red-700"
                    onClick={() => {
                      if (confirm(`Hapus desa "${d.nama}" beserta seluruh isinya?`))
                        run(() => api(`/desas/${d.id}`, { method: "DELETE" }));
                    }}
                  >Hapus</button>
                </span>
              </li>
            ))}
          </ul>
        </section>

        <section className={kolom}>
          <div className={header}>
            <h3 className="text-sm font-semibold">Kelompok</h3>
            <button onClick={tambahKelompok} className={btnTambah}>+ Tambah</button>
          </div>
          <ul className="divide-y divide-gray-100">
            {kelompoks.map((k) => (
              <li key={k.id} className={baris}>
                <span>{k.nama} <span className="text-gray-400">({k.desa?.nama})</span></span>
                <span className="flex gap-2">
                  <button
                    className={aksi}
                    onClick={() => {
                      const data = edit(`/kelompoks/${k.id}`, k.nama);
                      if (data) run(() => api(`/kelompoks/${k.id}`, { method: "PUT", body: JSON.stringify({ ...data, desa_id: k.desa_id }) }));
                    }}
                  >Edit</button>
                  <button
                    className="text-xs text-red-400 hover:text-red-700"
                    onClick={() => {
                      if (confirm(`Hapus kelompok "${k.nama}"?`))
                        run(() => api(`/kelompoks/${k.id}`, { method: "DELETE" }));
                    }}
                  >Hapus</button>
                </span>
              </li>
            ))}
          </ul>
        </section>
      </div>
    </div>
  );
}
