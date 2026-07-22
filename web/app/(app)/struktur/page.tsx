"use client";

import { useCallback, useEffect, useState } from "react";
import { api } from "@/lib/api";

interface Daerah { id: number; nama: string; desas_count: number }
interface Desa { id: number; nama: string; daerah_id: number; kelompoks_count: number; daerah?: { nama: string } }
interface Kelompok { id: number; nama: string; desa_id: number; desa?: { nama: string } }

type Level = "daerah" | "desa" | "kelompok";

export default function StrukturPage() {
  const [daerahs, setDaerahs] = useState<Daerah[]>([]);
  const [desas, setDesas] = useState<Desa[]>([]);
  const [kelompoks, setKelompoks] = useState<Kelompok[]>([]);
  const [error, setError] = useState("");

  // Modal tambah: parent (daerah untuk desa, desa untuk kelompok) sengaja tetap
  // tersimpan di state ini (bukan di-reset tiap submit), jadi bisa tambah
  // beberapa nama berturut-turut di parent yang sama tanpa pilih ulang.
  const [modal, setModal] = useState<Level | null>(null);
  const [daerahId, setDaerahId] = useState<number | "">("");
  const [desaId, setDesaId] = useState<number | "">("");
  const [namaText, setNamaText] = useState("");
  const [modalError, setModalError] = useState("");
  const [saving, setSaving] = useState(false);

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

  function bukaTambah(level: Level) {
    if (level === "desa" && !daerahs.length) return alert("Buat daerah dulu");
    if (level === "kelompok" && !desas.length) return alert("Buat desa dulu");
    if (level === "desa" && daerahId === "") setDaerahId(daerahs[0].id);
    if (level === "kelompok" && desaId === "") setDesaId(desas[0].id);
    setModal(level);
    setNamaText("");
    setModalError("");
  }

  async function submitTambah() {
    const namas = namaText.split("\n").map((s) => s.trim()).filter(Boolean);
    if (!namas.length) return;
    setSaving(true);
    setModalError("");
    const gagal: string[] = [];

    for (const nama of namas) {
      try {
        if (modal === "daerah") {
          await api("/daerahs", { method: "POST", body: JSON.stringify({ nama }) });
        } else if (modal === "desa") {
          await api("/desas", { method: "POST", body: JSON.stringify({ nama, daerah_id: daerahId }) });
        } else if (modal === "kelompok") {
          await api("/kelompoks", { method: "POST", body: JSON.stringify({ nama, desa_id: desaId }) });
        }
      } catch (err) {
        gagal.push(`${nama}: ${err instanceof Error ? err.message : "gagal"}`);
      }
    }

    reload();
    setNamaText("");
    setSaving(false);
    setModalError(gagal.join("\n"));
  }

  function edit(current: string) {
    const nama = prompt("Nama baru:", current);
    return nama && nama !== current ? { nama } : null;
  }

  const kolom = "rounded-xl border border-gray-200 bg-white";
  const header = "flex items-center justify-between border-b border-gray-200 p-3";
  const btnTambah = "rounded bg-emerald-600 px-2 py-1 text-xs font-semibold text-white hover:bg-emerald-700";
  const baris = "flex items-center justify-between px-3 py-2 text-sm";
  const aksi = "text-xs text-gray-400 hover:text-gray-700";
  const input = "w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 focus:border-emerald-500 focus:outline-none";
  const label = "mb-1 block text-xs font-medium text-gray-600";

  const JUDUL: Record<Level, string> = { daerah: "Daerah", desa: "Desa", kelompok: "Kelompok" };

  return (
    <div className="space-y-4">
      <h2 className="text-xl font-bold text-gray-900">Struktur Organisasi</h2>
      {error && <p className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}

      <div className="grid gap-4 lg:grid-cols-3">
        <section className={kolom}>
          <div className={header}>
            <h3 className="text-sm font-semibold">Daerah</h3>
            <button onClick={() => bukaTambah("daerah")} className={btnTambah}>+ Tambah</button>
          </div>
          <ul className="divide-y divide-gray-100">
            {daerahs.map((d) => (
              <li key={d.id} className={baris}>
                <span>{d.nama} <span className="text-gray-400">({d.desas_count} desa)</span></span>
                <span className="flex gap-2">
                  <button
                    className={aksi}
                    onClick={() => {
                      const data = edit(d.nama);
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
            <button onClick={() => bukaTambah("desa")} className={btnTambah}>+ Tambah</button>
          </div>
          <ul className="divide-y divide-gray-100">
            {desas.map((d) => (
              <li key={d.id} className={baris}>
                <span>{d.nama} <span className="text-gray-400">({d.kelompoks_count} kelompok)</span></span>
                <span className="flex gap-2">
                  <button
                    className={aksi}
                    onClick={() => {
                      const data = edit(d.nama);
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
            <button onClick={() => bukaTambah("kelompok")} className={btnTambah}>+ Tambah</button>
          </div>
          <ul className="divide-y divide-gray-100">
            {kelompoks.map((k) => (
              <li key={k.id} className={baris}>
                <span>{k.nama} <span className="text-gray-400">({k.desa?.nama})</span></span>
                <span className="flex gap-2">
                  <button
                    className={aksi}
                    onClick={() => {
                      const data = edit(k.nama);
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

      {modal && (
        <div className="fixed inset-0 z-10 flex items-start justify-center overflow-y-auto bg-black/30 p-4">
          <div className="my-8 w-full max-w-md space-y-4 rounded-xl bg-white p-6 shadow-xl">
            <h3 className="text-lg font-bold text-gray-900">Tambah {JUDUL[modal]}</h3>

            {modal === "desa" && (
              <div>
                <label className={label}>Daerah *</label>
                <select className={input} value={daerahId}
                  onChange={(e) => setDaerahId(Number(e.target.value))}>
                  {daerahs.map((d) => <option key={d.id} value={d.id}>{d.nama}</option>)}
                </select>
              </div>
            )}

            {modal === "kelompok" && (
              <div>
                <label className={label}>Desa *</label>
                <select className={input} value={desaId}
                  onChange={(e) => setDesaId(Number(e.target.value))}>
                  {desas.map((d) => <option key={d.id} value={d.id}>{d.nama}</option>)}
                </select>
              </div>
            )}

            <div>
              <label className={label}>
                Nama {JUDUL[modal]} * <span className="font-normal text-gray-400">(satu nama per baris, bisa banyak sekaligus)</span>
              </label>
              <textarea
                rows={5}
                className={input}
                value={namaText}
                onChange={(e) => setNamaText(e.target.value)}
                placeholder={`${JUDUL[modal]} A\n${JUDUL[modal]} B`}
              />
            </div>

            {modalError && <p className="whitespace-pre-line rounded bg-red-50 p-2 text-xs text-red-700">{modalError}</p>}

            <div className="flex justify-end gap-2">
              <button type="button" onClick={() => setModal(null)}
                className="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Tutup</button>
              <button type="button" disabled={saving} onClick={submitTambah}
                className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50">
                {saving ? "Menyimpan..." : "Simpan"}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
