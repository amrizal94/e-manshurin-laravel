"use client";

import { useCallback, useEffect, useState } from "react";
import { useParams } from "next/navigation";
import Link from "next/link";
import { api } from "@/lib/api";
import { JENIS_PENGAJIAN, KATEGORI_USIA } from "@/lib/labels";

interface Opsi { id: number; nama: string }
interface Kegiatan {
  id: number;
  nama: string;
  jenis_pengajian: string;
  tanggal: string;
  jam_mulai: string | null;
  jam_selesai: string | null;
  daerah?: Opsi | null;
  desa?: Opsi | null;
  kelompok?: Opsi | null;
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
  const [loading, setLoading] = useState(true);
  const [daerahs, setDaerahs] = useState<Opsi[]>([]);
  const [desas, setDesas] = useState<Opsi[]>([]);
  const [kelompoks, setKelompoks] = useState<Opsi[]>([]);
  const [form, setForm] = useState<{
    nama: string; jenis_pengajian: string; target: string;
    tanggal: string; jam_mulai: string; jam_selesai: string;
  } | null>(null);

  const reload = useCallback(() => {
    Promise.resolve().then(() => setLoading(true)); // defer 1 microtask: react-hooks/set-state-in-effect gak suka setState sinkron di body effect
    api<Kegiatan>(`/kegiatans/${id}`).then((r) => setKegiatan(r.data)).catch((e) => setError(e.message));
    api<Peserta[]>(`/kegiatans/${id}/peserta`).then((r) => setPeserta(r.data)).catch((e) => setError(e.message)).finally(() => setLoading(false));
  }, [id]);

  useEffect(reload, [reload]);
  useEffect(() => {
    api<Opsi[]>("/daerahs").then((r) => setDaerahs(r.data)).catch(() => {});
    api<Opsi[]>("/desas").then((r) => setDesas(r.data)).catch(() => {});
    api<Opsi[]>("/kelompoks").then((r) => setKelompoks(r.data)).catch(() => {});
  }, []);

  function bukaEdit() {
    if (!kegiatan) return;
    setError("");
    const target = kegiatan.kelompok ? `kelompok:${kegiatan.kelompok.id}`
      : kegiatan.desa ? `desa:${kegiatan.desa.id}`
      : kegiatan.daerah ? `daerah:${kegiatan.daerah.id}` : "";
    setForm({
      nama: kegiatan.nama,
      jenis_pengajian: kegiatan.jenis_pengajian,
      target,
      tanggal: kegiatan.tanggal.slice(0, 10),
      jam_mulai: kegiatan.jam_mulai?.slice(0, 5) ?? "",
      jam_selesai: kegiatan.jam_selesai?.slice(0, 5) ?? "",
    });
  }

  async function simpanEdit(e: React.FormEvent) {
    e.preventDefault();
    if (!form || !kegiatan) return;
    setError("");
    const [level, targetId] = form.target.split(":");
    try {
      await api(`/kegiatans/${kegiatan.id}`, {
        method: "PUT",
        body: JSON.stringify({
          nama: form.nama,
          jenis_pengajian: form.jenis_pengajian,
          daerah_id: level === "daerah" ? Number(targetId) : null,
          desa_id: level === "desa" ? Number(targetId) : null,
          kelompok_id: level === "kelompok" ? Number(targetId) : null,
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
        <div className="flex flex-wrap items-start justify-between gap-2">
          <div className="min-w-0">
            <h2 className="break-words text-xl font-bold text-gray-900">{kegiatan.nama}</h2>
            <p className="text-sm text-gray-500">
              {JENIS_PENGAJIAN[kegiatan.jenis_pengajian]} · {kegiatan.tanggal.slice(0, 10)} ·{" "}
              {peserta.length} peserta · {hadir} hadir · {izin} izin · {belum} belum tercatat
            </p>
          </div>
          <div className="flex flex-wrap gap-2">
            <button
              onClick={bukaEdit}
              className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"
            >
              Edit
            </button>
            <Link
              href={`/kegiatan/${kegiatan.id}/absen-wajah`}
              className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700"
            >
              📷 Absen Wajah
            </Link>
          </div>
        </div>
      )}

      {form && (
        <div className="fixed inset-0 z-10 flex items-start justify-center overflow-y-auto bg-black/30 p-4">
          <form onSubmit={simpanEdit} className="my-8 w-full max-w-md space-y-4 rounded-xl bg-white p-6 shadow-xl">
            <h3 className="text-lg font-bold text-gray-900">Edit Kegiatan</h3>
            <div>
              <label className="mb-1 block text-xs font-medium text-gray-600" htmlFor="ke-nama">Nama Pengajian *</label>
              <input id="ke-nama" required className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 focus:border-emerald-500 focus:outline-none"
                value={form.nama} onChange={(e) => setForm({ ...form, nama: e.target.value })} />
            </div>
            <div>
              <label className="mb-1 block text-xs font-medium text-gray-600" htmlFor="ke-jenis_pengajian">Jenis Pengajian *</label>
              <select id="ke-jenis_pengajian" className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 focus:border-emerald-500 focus:outline-none"
                value={form.jenis_pengajian} onChange={(e) => setForm({ ...form, jenis_pengajian: e.target.value })}>
                {Object.entries(JENIS_PENGAJIAN).map(([v, l]) => (
                  <option key={v} value={v}>{l}</option>
                ))}
              </select>
            </div>
            <div>
              <label className="mb-1 block text-xs font-medium text-gray-600" htmlFor="ke-target">Target Struktur *</label>
              <select id="ke-target" required className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 focus:border-emerald-500 focus:outline-none"
                value={form.target} onChange={(e) => setForm({ ...form, target: e.target.value })}>
                <option value="">Pilih...</option>
                {daerahs.map((d) => <option key={`da${d.id}`} value={`daerah:${d.id}`}>Daerah — {d.nama}</option>)}
                {desas.map((d) => <option key={`de${d.id}`} value={`desa:${d.id}`}>Desa — {d.nama}</option>)}
                {kelompoks.map((k) => <option key={`ke${k.id}`} value={`kelompok:${k.id}`}>Kelompok — {k.nama}</option>)}
              </select>
            </div>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
              <div>
                <label className="mb-1 block text-xs font-medium text-gray-600" htmlFor="ke-tanggal">Tanggal *</label>
                <input id="ke-tanggal" type="date" required className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 focus:border-emerald-500 focus:outline-none"
                  value={form.tanggal} onChange={(e) => setForm({ ...form, tanggal: e.target.value })} />
              </div>
              <div>
                <label className="mb-1 block text-xs font-medium text-gray-600" htmlFor="ke-jam_mulai">Jam Mulai</label>
                <input id="ke-jam_mulai" type="time" className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 focus:border-emerald-500 focus:outline-none"
                  value={form.jam_mulai} onChange={(e) => setForm({ ...form, jam_mulai: e.target.value })} />
              </div>
              <div>
                <label className="mb-1 block text-xs font-medium text-gray-600" htmlFor="ke-jam_selesai">Jam Selesai</label>
                <input id="ke-jam_selesai" type="time" className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 focus:border-emerald-500 focus:outline-none"
                  value={form.jam_selesai} onChange={(e) => setForm({ ...form, jam_selesai: e.target.value })} />
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
            {!loading && peserta.length === 0 && (
              <tr><td colSpan={5} className="p-6 text-center text-gray-400">Tidak ada peserta</td></tr>
            )}
            {loading && (
              <tr><td colSpan={5} className="p-6 text-center text-gray-400">Memuat...</td></tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
