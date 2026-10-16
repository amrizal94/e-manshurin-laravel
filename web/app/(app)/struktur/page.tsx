"use client";

import { useCallback, useEffect, useState } from "react";
import { api } from "@/lib/api";
import { useKonfirmasi } from "@/components/Konfirmasi";
import { useRoleGuard } from "@/lib/useRoleGuard";

interface Daerah { id: number; nama: string; desas_count: number }
interface Desa { id: number; nama: string; daerah_id: number; kelompoks_count: number; daerah?: { nama: string } }
interface Kelompok { id: number; nama: string; desa_id: number; desa?: { nama: string } }

type Level = "daerah" | "desa" | "kelompok";

export default function StrukturPage() {
  const tanya = useKonfirmasi();
  // Kolom saling menyaring. Tanpa ini kolom Kelompok memuat seluruh kelompok semua desa
  // sekaligus — 50 baris sekarang, ratusan begitu daerahnya bertambah, padahal yang dicari
  // petugas cuma satu. Belum memilih apa pun = tampil semua, supaya wilayah kecil tetap
  // kelihatan utuh dalam sekali lihat.
  const [pilihDaerah, setPilihDaerah] = useState<number | null>(null);
  const [pilihDesa, setPilihDesa] = useState<number | null>(null);
  useRoleGuard(["super_admin", "admin"]);
  const [daerahs, setDaerahs] = useState<Daerah[]>([]);
  const [desas, setDesas] = useState<Desa[]>([]);
  const [kelompoks, setKelompoks] = useState<Kelompok[]>([]);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(true);

  // Modal tambah: parent (daerah untuk desa, desa untuk kelompok) sengaja tetap
  // tersimpan di state ini (bukan di-reset tiap submit), jadi bisa tambah
  // beberapa nama berturut-turut di parent yang sama tanpa pilih ulang.
  const [modal, setModal] = useState<Level | null>(null);
  const [daerahId, setDaerahId] = useState<number | "">("");
  const [desaId, setDesaId] = useState<number | "">("");
  const [namaText, setNamaText] = useState("");
  const [modalError, setModalError] = useState("");
  const [sesiDitambahkan, setSesiDitambahkan] = useState<string[]>([]);
  const [saving, setSaving] = useState(false);

  const reload = useCallback(() => {
    // defer 1 microtask: react-hooks/set-state-in-effect gak suka setState sinkron di body effect
    Promise.resolve().then(() => { setError(""); setLoading(true); });
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
      .catch((err) => setError(err.message))
      .finally(() => setLoading(false));
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
    // Bukan pertanyaan, cuma pemberitahuan — dipasang di tempat yang sama dengan galat lain.
    if (level === "desa" && !daerahs.length) return setError("Buat daerah dulu");
    if (level === "kelompok" && !desas.length) return setError("Buat desa dulu");
    // Desa yang sedang dipilih jadi isian awal: petugas baru saja melihatnya di layar,
    // tidak perlu memilihnya sekali lagi di dalam formulir.
    if (level === "desa") setDaerahId(pilihDaerah ?? (daerahId === "" ? daerahs[0].id : daerahId));
    if (level === "kelompok") setDesaId(pilihDesa ?? (desaId === "" ? desas[0].id : desaId));
    setModal(level);
    setNamaText("");
    setModalError("");
    setSesiDitambahkan([]);
  }

  async function submitTambah() {
    const namas = namaText.split("\n").map((s) => s.trim()).filter(Boolean);
    if (!namas.length) return;
    setSaving(true);
    setModalError("");
    const sukses: string[] = [];
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
        sukses.push(nama);
      } catch (err) {
        gagal.push(`${nama}: ${err instanceof Error ? err.message : "gagal"}`);
      }
    }

    reload();
    setNamaText("");
    setSaving(false);
    if (sukses.length) setSesiDitambahkan((s) => [...s, ...sukses]);
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

  // Desa & Kelompok dikelompokkan per induk (Daerah / Desa) biar gak campur
  // urut abjad lintas induk — daerahs & desas sendiri sudah terurut abjad dari API.
  const desaTampil = pilihDaerah ? desas.filter((d) => d.daerah_id === pilihDaerah) : desas;

  // Desa yang dipilih menang atas daerah yang dipilih: yang lebih sempit yang berlaku.
  const kelompokTampil = pilihDesa
    ? kelompoks.filter((k) => k.desa_id === pilihDesa)
    : kelompoks.filter((k) => desaTampil.some((d) => d.id === k.desa_id));

  const desaGroups = daerahs
    .map((da) => ({ induk: da, items: desaTampil.filter((d) => d.daerah_id === da.id) }))
    .filter((g) => g.items.length > 0);

  const kelompokGroups = desas
    .map((de) => ({ induk: de, items: kelompokTampil.filter((k) => k.desa_id === de.id) }))
    .filter((g) => g.items.length > 0);

  const namaDaerahDipilih = daerahs.find((d) => d.id === pilihDaerah)?.nama;
  const namaDesaDipilih = desas.find((d) => d.id === pilihDesa)?.nama;

  /** Melepas daerah ikut melepas desa — desanya sudah tidak kelihatan lagi untuk dilepas. */
  function pilihDaerahnya(id: number) {
    const sama = pilihDaerah === id;
    setPilihDaerah(sama ? null : id);
    setPilihDesa(null);
  }

  const grupHeader = "bg-gray-50 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-gray-500";

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
                <button
                  onClick={() => pilihDaerahnya(d.id)}
                  aria-pressed={pilihDaerah === d.id}
                  className={`min-w-0 truncate text-left ${pilihDaerah === d.id ? "font-semibold text-emerald-700" : ""}`}
                >
                  {d.nama} <span className="text-gray-400">({d.desas_count} desa)</span>
                </button>
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
                    onClick={async () => {
                      if (await tanya({ pesan: `Hapus daerah "${d.nama}"?`, keterangan: "Desa, kelompok, dan jamaah di dalamnya ikut terhapus.", bahaya: true }))
                        run(() => api(`/daerahs/${d.id}`, { method: "DELETE" }));
                    }}
                  >Hapus</button>
                </span>
              </li>
            ))}
            {!loading && daerahs.length === 0 && <p className="p-6 text-center text-sm text-gray-400">Belum ada data</p>}
            {loading && <p className="p-6 text-center text-sm text-gray-400">Memuat...</p>}
          </ul>
        </section>

        <section className={kolom}>
          <div className={header}>
            <h3 className="flex min-w-0 items-center gap-2 text-sm font-semibold">
              Desa
              {namaDaerahDipilih && (
                <button
                  onClick={() => pilihDaerahnya(pilihDaerah!)}
                  className="flex min-w-0 items-center gap-1 rounded border border-emerald-300 bg-emerald-50 px-2 py-0.5 text-xs font-normal text-emerald-700"
                  title="Lepas saringan"
                >
                  <span className="truncate">{namaDaerahDipilih}</span> ×
                </button>
              )}
            </h3>
            <button onClick={() => bukaTambah("desa")} className={btnTambah}>+ Tambah</button>
          </div>
          <div className="divide-y divide-gray-100">
            {desaGroups.map((g) => (
              <div key={g.induk.id}>
                <p className={grupHeader}>{g.induk.nama}</p>
                <ul className="divide-y divide-gray-100">
                  {g.items.map((d) => (
                    <li key={d.id} className={baris}>
                      <button
                        onClick={() => setPilihDesa(pilihDesa === d.id ? null : d.id)}
                        aria-pressed={pilihDesa === d.id}
                        className={`min-w-0 truncate text-left ${pilihDesa === d.id ? "font-semibold text-emerald-700" : ""}`}
                      >
                        {d.nama} <span className="text-gray-400">({d.kelompoks_count} kelompok)</span>
                      </button>
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
                          onClick={async () => {
                      if (await tanya({ pesan: `Hapus desa "${d.nama}"?`, keterangan: "Kelompok dan jamaah di dalamnya ikut terhapus.", bahaya: true }))
                        run(() => api(`/desas/${d.id}`, { method: "DELETE" }));
                    }}
                        >Hapus</button>
                      </span>
                    </li>
                  ))}
                </ul>
              </div>
            ))}
            {!loading && desas.length === 0 && <p className="p-6 text-center text-sm text-gray-400">Belum ada data</p>}
            {loading && <p className="p-6 text-center text-sm text-gray-400">Memuat...</p>}
          </div>
        </section>

        <section className={kolom}>
          <div className={header}>
            <h3 className="flex min-w-0 items-center gap-2 text-sm font-semibold">
              Kelompok
              {(namaDesaDipilih ?? namaDaerahDipilih) && (
                <button
                  onClick={() => (pilihDesa ? setPilihDesa(null) : pilihDaerahnya(pilihDaerah!))}
                  className="flex min-w-0 items-center gap-1 rounded border border-emerald-300 bg-emerald-50 px-2 py-0.5 text-xs font-normal text-emerald-700"
                  title="Lepas saringan"
                >
                  <span className="truncate">{namaDesaDipilih ?? namaDaerahDipilih}</span> ×
                </button>
              )}
            </h3>
            <button onClick={() => bukaTambah("kelompok")} className={btnTambah}>+ Tambah</button>
          </div>
          <div className="divide-y divide-gray-100">
            {kelompokGroups.map((g) => (
              <div key={g.induk.id}>
                <p className={grupHeader}>{g.induk.nama}</p>
                <ul className="divide-y divide-gray-100">
                  {g.items.map((k) => (
                    <li key={k.id} className={baris}>
                      <span>{k.nama}</span>
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
                          onClick={async () => {
                      if (await tanya({ pesan: `Hapus kelompok "${k.nama}"?`, keterangan: "Jamaah di kelompok ini ikut terhapus.", bahaya: true }))
                        run(() => api(`/kelompoks/${k.id}`, { method: "DELETE" }));
                    }}
                        >Hapus</button>
                      </span>
                    </li>
                  ))}
                </ul>
              </div>
            ))}
            {!loading && kelompoks.length === 0 && <p className="p-6 text-center text-sm text-gray-400">Belum ada data</p>}
            {loading && <p className="p-6 text-center text-sm text-gray-400">Memuat...</p>}
          </div>
        </section>
      </div>

      {modal && (
        <div className="fixed inset-0 z-10 flex items-start justify-center overflow-y-auto bg-black/30 p-4">
          <div className="my-8 w-full max-w-md space-y-4 rounded-xl bg-white p-6 shadow-xl">
            <h3 className="text-lg font-bold text-gray-900">Tambah {JUDUL[modal]}</h3>

            {modal === "desa" && (
              <div>
                <label className={label} htmlFor="st-daerah">Daerah *</label>
                <select id="st-daerah" className={input} value={daerahId}
                  onChange={(e) => setDaerahId(Number(e.target.value))}>
                  {daerahs.map((d) => <option key={d.id} value={d.id}>{d.nama}</option>)}
                </select>
              </div>
            )}

            {modal === "kelompok" && (
              <div>
                <label className={label} htmlFor="st-desa">Desa *</label>
                <select id="st-desa" className={input} value={desaId}
                  onChange={(e) => setDesaId(Number(e.target.value))}>
                  {desas.map((d) => <option key={d.id} value={d.id}>{d.nama}</option>)}
                </select>
              </div>
            )}

            {sesiDitambahkan.length > 0 && (
              <div className="rounded-lg bg-emerald-50 p-2">
                <p className="mb-1 text-xs font-medium text-emerald-700">
                  ✓ Sudah masuk daftar ({sesiDitambahkan.length}):
                </p>
                <ul className="max-h-24 space-y-0.5 overflow-y-auto text-xs text-emerald-700">
                  {sesiDitambahkan.map((n, i) => <li key={i}>{n}</li>)}
                </ul>
              </div>
            )}

            <div>
              <label className={label} htmlFor="st-nama-text">
                Nama {JUDUL[modal]} * <span className="font-normal text-gray-400">(satu nama per baris, bisa banyak sekaligus)</span>
              </label>
              <textarea
                id="st-nama-text"
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
