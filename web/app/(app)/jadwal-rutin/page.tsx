"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { api } from "@/lib/api";
import { JENIS_PENGAJIAN } from "@/lib/labels";
import { useRoleGuard } from "@/lib/useRoleGuard";

interface Opsi { id: number; nama: string }
interface JadwalRutin {
  id: number;
  nama: string;
  jenis_pengajian: string;
  hari: number[];
  jam_mulai: string;
  jam_selesai: string;
  aktif: boolean;
  daerah?: Opsi | null;
  desa?: Opsi | null;
  kelompok?: Opsi | null;
  kegiatan_mendatang_count: number;
}

/** Urutannya mengikuti Carbon::dayOfWeek di server: 0 Minggu sampai 6 Sabtu. */
const HARI = ["Minggu", "Senin", "Selasa", "Rabu", "Kamis", "Jumat", "Sabtu"];

const KOSONG = {
  nama: "", jenis_pengajian: "umum", target: "",
  hari: [] as number[], jam_mulai: "", jam_selesai: "", aktif: true,
};

export default function JadwalRutinPage() {
  useRoleGuard(["super_admin", "admin"]);
  const [rows, setRows] = useState<JadwalRutin[]>([]);
  const [daerahs, setDaerahs] = useState<Opsi[]>([]);
  const [desas, setDesas] = useState<Opsi[]>([]);
  const [kelompoks, setKelompoks] = useState<Opsi[]>([]);
  const [form, setForm] = useState<typeof KOSONG | null>(null);
  const [editId, setEditId] = useState<number | null>(null);
  const [error, setError] = useState("");
  const [kabar, setKabar] = useState("");
  const [loading, setLoading] = useState(true);

  const reload = useCallback(() => {
    Promise.resolve().then(() => setLoading(true)); // defer 1 microtask: react-hooks/set-state-in-effect gak suka setState sinkron di body effect
    api<JadwalRutin[]>("/jadwal-rutins")
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

  function buka(j?: JadwalRutin) {
    setError("");
    setKabar("");
    setEditId(j?.id ?? null);
    setForm(j ? {
      nama: j.nama,
      jenis_pengajian: j.jenis_pengajian,
      target: j.kelompok ? `kelompok:${j.kelompok.id}`
        : j.desa ? `desa:${j.desa.id}`
          : j.daerah ? `daerah:${j.daerah.id}` : "",
      hari: j.hari ?? [],
      jam_mulai: j.jam_mulai.slice(0, 5),
      jam_selesai: j.jam_selesai.slice(0, 5),
      aktif: j.aktif,
    } : KOSONG);
  }

  function toggleHari(h: number) {
    if (!form) return;
    setForm({
      ...form,
      hari: form.hari.includes(h) ? form.hari.filter((x) => x !== h) : [...form.hari, h],
    });
  }

  async function simpan(e: React.FormEvent) {
    e.preventDefault();
    if (!form) return;
    setError("");
    if (form.hari.length === 0) {
      setError("Pilih minimal satu hari.");
      return;
    }
    const [level, id] = form.target.split(":");
    try {
      const res = await api<unknown>(editId ? `/jadwal-rutins/${editId}` : "/jadwal-rutins", {
        method: editId ? "PUT" : "POST",
        body: JSON.stringify({
          nama: form.nama,
          jenis_pengajian: form.jenis_pengajian,
          daerah_id: level === "daerah" ? Number(id) : null,
          desa_id: level === "desa" ? Number(id) : null,
          kelompok_id: level === "kelompok" ? Number(id) : null,
          hari: form.hari,
          jam_mulai: form.jam_mulai,
          jam_selesai: form.jam_selesai,
          aktif: form.aktif,
        }),
      });
      setForm(null);
      // Pesan server menyebut berapa kegiatan terbit dan tanggal mana yang dilewati
      // karena bentrok — itu yang perlu dibaca, bukan sekadar "tersimpan".
      setKabar(res.message);
      reload();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Gagal menyimpan");
    }
  }

  async function hapus(j: JadwalRutin) {
    if (!confirm(`Hapus jadwal "${j.nama}"?\n\nKegiatan yang sudah terbit tetap ada.`)) return;
    try {
      const res = await api<unknown>(`/jadwal-rutins/${j.id}`, { method: "DELETE" });
      setKabar(res.message);
      reload();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Gagal menghapus");
    }
  }

  const input = "w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none";
  const label = "mb-1 block text-xs font-medium text-gray-600";

  function target(j: JadwalRutin) {
    return j.kelompok ? `Kelompok ${j.kelompok.nama}`
      : j.desa ? `Desa ${j.desa.nama}`
        : j.daerah ? `Daerah ${j.daerah.nama}` : "-";
  }

  function hariRingkas(hari: number[]) {
    return [...hari].sort((a, b) => a - b).map((h) => HARI[h]?.slice(0, 3)).join(", ");
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between gap-2">
        <h2 className="text-xl font-bold text-gray-900">Jadwal Rutin</h2>
        <button onClick={() => buka()}
          className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
          + Buat Jadwal
        </button>
      </div>

      <p className="text-sm text-gray-500">
        Jadwal yang berulang tiap pekan. Kegiatannya diterbitkan otomatis 30 hari ke depan dan
        diperpanjang tiap hari. Kalau ada yang libur, tandai kegiatannya di{" "}
        <Link href="/kegiatan" className="font-medium text-emerald-700 hover:underline">halaman Kegiatan</Link>
        {" "}— jangan dihapus, karena yang dihapus akan terbit lagi.
      </p>

      {error && <p className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
      {kabar && <p className="rounded bg-emerald-50 p-2 text-sm text-emerald-800">{kabar}</p>}

      <div className="space-y-2">
        {loading && (
          <p className="rounded-xl border border-gray-200 bg-white p-6 text-center text-gray-400">Memuat...</p>
        )}
        {!loading && rows.length === 0 && (
          <p className="rounded-xl border border-gray-200 bg-white p-6 text-center text-gray-400">Belum ada jadwal rutin</p>
        )}
        {rows.map((j) => (
          <div key={j.id} className="rounded-xl border border-gray-200 bg-white p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
              <div className="min-w-0">
                <p className="font-medium text-gray-900">
                  {j.nama}
                  {!j.aktif && <span className="ml-2 rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-500">Nonaktif</span>}
                </p>
                <p className="text-sm text-gray-600">
                  {hariRingkas(j.hari)} · {j.jam_mulai.slice(0, 5)}–{j.jam_selesai.slice(0, 5)}
                </p>
                <p className="text-xs text-gray-400">
                  {JENIS_PENGAJIAN[j.jenis_pengajian]} · {target(j)}
                </p>
              </div>
              <div className="flex shrink-0 gap-3 text-xs">
                <button onClick={() => buka(j)} className="text-gray-500 hover:text-gray-800">Edit</button>
                <button onClick={() => hapus(j)} className="text-red-400 hover:text-red-700">Hapus</button>
              </div>
            </div>
            <p className="mt-2 border-t border-gray-100 pt-2 text-xs text-gray-400">
              {j.kegiatan_mendatang_count} kegiatan sudah terbit sampai hari ini ke depan
            </p>
          </div>
        ))}
      </div>

      {form && (
        <div className="fixed inset-0 z-10 flex items-start justify-center overflow-y-auto bg-black/30 p-4">
          <form onSubmit={simpan} className="my-8 w-full max-w-md space-y-4 rounded-xl bg-white p-6 shadow-xl">
            <h3 className="text-lg font-bold text-gray-900">{editId ? "Edit" : "Buat"} Jadwal Rutin</h3>

            <div>
              <label className={label} htmlFor="jr-nama">Nama Pengajian *</label>
              <input id="jr-nama" required className={input} value={form.nama}
                onChange={(e) => setForm({ ...form, nama: e.target.value })} />
            </div>
            <div>
              <label className={label} htmlFor="jr-jenis">Jenis Pengajian *</label>
              <select id="jr-jenis" className={input} value={form.jenis_pengajian}
                onChange={(e) => setForm({ ...form, jenis_pengajian: e.target.value })}>
                {Object.entries(JENIS_PENGAJIAN).map(([v, l]) => (
                  <option key={v} value={v}>{l}</option>
                ))}
              </select>
            </div>
            <div>
              <label className={label} htmlFor="jr-target">Target Struktur *</label>
              <select id="jr-target" required className={input} value={form.target}
                onChange={(e) => setForm({ ...form, target: e.target.value })}>
                <option value="">Pilih...</option>
                {daerahs.map((d) => <option key={`da${d.id}`} value={`daerah:${d.id}`}>Daerah — {d.nama}</option>)}
                {desas.map((d) => <option key={`de${d.id}`} value={`desa:${d.id}`}>Desa — {d.nama}</option>)}
                {kelompoks.map((k) => <option key={`ke${k.id}`} value={`kelompok:${k.id}`}>Kelompok — {k.nama}</option>)}
              </select>
            </div>

            <fieldset>
              <legend className={label}>Hari *</legend>
              <div className="flex flex-wrap gap-2">
                {HARI.map((nama, h) => (
                  <button key={h} type="button" onClick={() => toggleHari(h)}
                    aria-pressed={form.hari.includes(h)}
                    className={`rounded-lg border px-3 py-1.5 text-sm ${form.hari.includes(h)
                      ? "border-emerald-600 bg-emerald-600 font-semibold text-white"
                      : "border-gray-300 text-gray-700 hover:bg-gray-50"}`}>
                    {nama.slice(0, 3)}
                  </button>
                ))}
              </div>
              <p className="mt-1 text-xs text-gray-400">Pondok yang mengaji tiap hari: centang ketujuhnya.</p>
            </fieldset>

            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className={label} htmlFor="jr-jam_mulai">Jam Mulai *</label>
                <input id="jr-jam_mulai" type="time" required className={input} value={form.jam_mulai}
                  onChange={(e) => setForm({ ...form, jam_mulai: e.target.value })} />
              </div>
              <div>
                <label className={label} htmlFor="jr-jam_selesai">Jam Selesai *</label>
                <input id="jr-jam_selesai" type="time" required className={input} value={form.jam_selesai}
                  onChange={(e) => setForm({ ...form, jam_selesai: e.target.value })} />
              </div>
            </div>

            <label className="flex items-center gap-2 text-sm text-gray-700">
              <input type="checkbox" checked={form.aktif}
                onChange={(e) => setForm({ ...form, aktif: e.target.checked })} />
              Aktif (menerbitkan kegiatan)
            </label>

            {editId && (
              <p className="rounded bg-gray-50 p-2 text-xs text-gray-500">
                Kegiatan yang sudah terbit tidak ikut berubah — sebagiannya mungkin sudah ada
                absensinya. Perubahan ini berlaku untuk yang terbit sesudah ini.
              </p>
            )}

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
