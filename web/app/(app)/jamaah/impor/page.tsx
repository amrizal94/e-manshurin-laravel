"use client";

import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import { api, unduh } from "@/lib/api";
import { KATEGORI_USIA } from "@/lib/labels";
import { useRoleGuard } from "@/lib/useRoleGuard";

interface Kelompok { id: number; nama: string; desa?: { nama: string } }

interface Baris {
  baris: number;
  nama_lengkap: string;
  kelompok: string;
  tanggal_lahir: string | null;
  status: "siap" | "perhatian" | "error";
  pesan: string[];
}

interface Hasil {
  ringkasan: { total: number; siap: number; perhatian: number; error: number };
  catatan: string[];
  baris: Baris[];
  dipotong: number;
}

const WAJIB = ["desa", "kelompok", "nama_lengkap", "jenis_kelamin", "kategori_usia"];
const OPSIONAL = [
  "nama_panggilan", "tempat_lahir", "tanggal_lahir", "alamat", "no_hp",
  "pekerjaan", "status_kk", "status_mubaligh", "aktif", "keterangan_tidak_aktif",
];

const WARNA: Record<Baris["status"], string> = {
  siap: "bg-emerald-50 text-emerald-700",
  perhatian: "bg-amber-50 text-amber-700",
  error: "bg-red-50 text-red-700",
};

const LABEL_STATUS: Record<Baris["status"], string> = {
  siap: "Siap",
  perhatian: "Perhatian",
  error: "Error",
};

export default function ImporJamaahPage() {
  useRoleGuard(["super_admin", "admin"]);
  const [kelompoks, setKelompoks] = useState<Kelompok[]>([]);
  const [hasil, setHasil] = useState<Hasil | null>(null);
  const [error, setError] = useState("");
  const [sibuk, setSibuk] = useState(false);
  const fileRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    api<Kelompok[]>("/kelompoks").then((res) => setKelompoks(res.data)).catch(() => {});
  }, []);

  async function ambilTemplate() {
    setError("");
    try {
      await unduh("/jamaahs/impor/template", "template-impor-jamaah.csv");
    } catch (err) {
      setError(err instanceof Error ? err.message : "Gagal mengunduh templat");
    }
  }

  async function periksa() {
    const file = fileRef.current?.files?.[0];
    if (!file) return;
    setError("");
    setHasil(null);
    setSibuk(true);
    const body = new FormData();
    body.append("file", file);
    try {
      const res = await api<Hasil>("/jamaahs/impor/periksa", { method: "POST", body });
      setHasil(res.data);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Gagal memeriksa file");
    } finally {
      setSibuk(false);
    }
  }

  const kotak = "rounded-xl border border-gray-200 bg-white p-4";

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h2 className="text-xl font-bold text-gray-900">Impor Jamaah</h2>
        <Link href="/jamaah" className="text-sm text-gray-500 hover:text-gray-800">← Data Jamaah</Link>
      </div>

      {error && <p className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}

      <div className={kotak}>
        <h3 className="font-semibold text-gray-900">1. Unduh templat</h3>
        <p className="mt-1 text-sm text-gray-600">
          Isi datanya di templat ini, jangan bikin kolom sendiri. Simpan lewat <b>File → Save As → CSV</b>.
        </p>
        <button onClick={ambilTemplate}
          className="mt-3 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
          Unduh template-impor-jamaah.csv
        </button>

        <dl className="mt-4 space-y-2 text-sm">
          <div>
            <dt className="text-xs font-medium text-gray-500">Kolom wajib</dt>
            <dd className="font-mono text-xs text-gray-800">{WAJIB.join(", ")}</dd>
          </div>
          <div>
            <dt className="text-xs font-medium text-gray-500">Kolom opsional (boleh dikosongkan)</dt>
            <dd className="font-mono text-xs text-gray-500">{OPSIONAL.join(", ")}</dd>
          </div>
          <div>
            <dt className="text-xs font-medium text-gray-500">Isi kategori_usia</dt>
            <dd className="font-mono text-xs text-gray-800">{Object.keys(KATEGORI_USIA).join(", ")}</dd>
          </div>
          <div>
            <dt className="text-xs font-medium text-gray-500">Isi jenis_kelamin</dt>
            <dd className="font-mono text-xs text-gray-800">L, P</dd>
          </div>
          <div>
            <dt className="text-xs font-medium text-gray-500">Isi tanggal_lahir</dt>
            <dd className="text-xs text-gray-800">
              <span className="font-mono">1990-11-30</span> (tahun-bulan-tanggal). Kalau ragu,
              kosongkan saja — tanggal lahir salah lebih repot daripada kosong.
            </dd>
          </div>
        </dl>

        {/* Nama kelompok ada yang dipakai dua desa, jadi keduanya harus diisi persis seperti di sini. */}
        <details className="mt-4">
          <summary className="cursor-pointer text-sm font-medium text-gray-700">
            Ejaan desa &amp; kelompok yang sah ({kelompoks.length})
          </summary>
          <div className="mt-2 max-h-64 overflow-y-auto rounded border border-gray-100 bg-gray-50 p-2">
            <table className="w-full text-xs">
              <thead className="text-left text-gray-500">
                <tr><th className="p-1">desa</th><th className="p-1">kelompok</th></tr>
              </thead>
              <tbody className="font-mono text-gray-800">
                {kelompoks.map((k) => (
                  <tr key={k.id}><td className="p-1">{k.desa?.nama}</td><td className="p-1">{k.nama}</td></tr>
                ))}
              </tbody>
            </table>
          </div>
        </details>
      </div>

      <div className={kotak}>
        <h3 className="font-semibold text-gray-900">2. Periksa file</h3>
        <p className="mt-1 text-sm text-gray-600">
          Filenya cuma dibaca dan dilaporkan — <b>belum ada data yang tersimpan</b>.
          Maksimal 2000 baris per file; kalau lebih, pecah per desa.
        </p>
        <div className="mt-3 flex flex-wrap items-center gap-2">
          <input ref={fileRef} type="file" accept=".csv,text/csv" aria-label="File CSV"
            onChange={() => { setHasil(null); setError(""); }}
            className="text-sm text-gray-700 file:mr-3 file:rounded-lg file:border-0 file:bg-gray-100 file:px-3 file:py-2 file:text-sm file:font-medium" />
          <button onClick={periksa} disabled={sibuk}
            className="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-700 disabled:opacity-50">
            {sibuk ? "Memeriksa..." : "Periksa"}
          </button>
        </div>
      </div>

      {hasil && (
        <div className={kotak}>
          <h3 className="font-semibold text-gray-900">Hasil pemeriksaan</h3>

          <div className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
            {([
              ["Total baris", hasil.ringkasan.total, "bg-gray-50 text-gray-700"],
              ["Siap", hasil.ringkasan.siap, WARNA.siap],
              ["Perhatian", hasil.ringkasan.perhatian, WARNA.perhatian],
              ["Error", hasil.ringkasan.error, WARNA.error],
            ] as const).map(([judul, angka, warna]) => (
              <div key={judul} className={`rounded-lg p-3 ${warna}`}>
                <p className="text-xs">{judul}</p>
                <p className="text-2xl font-bold">{angka}</p>
              </div>
            ))}
          </div>

          {hasil.catatan.map((c) => (
            <p key={c} className="mt-3 rounded bg-amber-50 p-2 text-sm text-amber-800">{c}</p>
          ))}

          {hasil.ringkasan.error > 0 && (
            <p className="mt-3 text-sm text-gray-600">
              Perbaiki baris yang error di file aslinya, simpan ulang sebagai CSV, lalu periksa lagi.
            </p>
          )}

          <div className="mt-3 overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 text-left text-xs text-gray-500">
                <tr>
                  <th className="p-2">Baris</th>
                  <th className="p-2">Nama</th>
                  <th className="p-2">Kelompok</th>
                  <th className="p-2">Tgl Lahir</th>
                  <th className="p-2">Status</th>
                  <th className="p-2">Keterangan</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {hasil.baris.map((b) => (
                  <tr key={b.baris}>
                    <td className="p-2 text-gray-400">{b.baris}</td>
                    <td className="p-2 font-medium text-gray-900">{b.nama_lengkap || "-"}</td>
                    <td className="p-2 text-gray-600">{b.kelompok}</td>
                    <td className="p-2 text-gray-600">{b.tanggal_lahir ?? "-"}</td>
                    <td className="p-2">
                      <span className={`rounded px-2 py-0.5 text-xs ${WARNA[b.status]}`}>{LABEL_STATUS[b.status]}</span>
                    </td>
                    <td className="p-2 text-xs text-gray-600">{b.pesan.join("; ") || "—"}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {hasil.dipotong > 0 && (
            <p className="mt-2 text-xs text-gray-500">
              {hasil.dipotong} baris bermasalah lainnya tidak ditampilkan. Perbaiki yang di atas dulu, lalu periksa lagi.
            </p>
          )}

          <p className="mt-4 rounded bg-gray-50 p-2 text-sm text-gray-600">
            Langkah menyimpan ke database belum aktif. Pemeriksaan ini dulu yang dipakai
            memastikan filenya benar.
          </p>
        </div>
      )}
    </div>
  );
}
