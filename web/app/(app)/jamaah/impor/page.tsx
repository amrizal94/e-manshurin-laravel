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
  ringkasan: { total: number; siap: number; perhatian: number; error: number; kembar: number };
  catatan: string[];
  baris: Baris[];
  dipotong: number;
}

interface Impor {
  impor_id: string;
  disimpan: number;
  dilewati: number;
}

const WAJIB = ["desa", "kelompok", "nama_lengkap", "jenis_kelamin", "kategori_usia"];
const OPSIONAL = [
  "nama_panggilan", "tempat_lahir", "tanggal_lahir", "alamat", "no_hp",
  "pekerjaan", "status_kk", "kode_keluarga", "status_mubaligh", "aktif", "keterangan_tidak_aktif",
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
  const [impor, setImpor] = useState<Impor | null>(null);
  const [lewatiKembar, setLewatiKembar] = useState(true);
  const [error, setError] = useState("");
  const [sibuk, setSibuk] = useState(false);
  const fileRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    api<Kelompok[]>("/kelompoks").then((res) => setKelompoks(res.data)).catch(() => {});
  }, []);

  async function ambilTemplate(jenis: "xlsx" | "csv") {
    setError("");
    try {
      await unduh(
        jenis === "xlsx" ? "/jamaahs/impor/template-xlsx" : "/jamaahs/impor/template",
        `template-impor-jamaah.${jenis}`
      );
    } catch (err) {
      setError(err instanceof Error ? err.message : "Gagal mengunduh templat");
    }
  }

  async function periksa() {
    const file = fileRef.current?.files?.[0];
    if (!file) return;
    setError("");
    setHasil(null);
    setImpor(null);
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

  /** Filenya dikirim ulang, bukan hasil pemeriksaan tadi — yang tersimpan harus dibaca dari file yang sama. */
  async function simpan() {
    const file = fileRef.current?.files?.[0];
    if (!file || !hasil) return;
    const jumlah = hasil.ringkasan.total - (lewatiKembar ? hasil.ringkasan.kembar : 0);
    if (!confirm(`Simpan ${jumlah} jamaah ke database?\n\nBisa dibatalkan sekaligus setelah ini, selama belum ada yang diabsen.`)) return;

    setError("");
    setSibuk(true);
    const body = new FormData();
    body.append("file", file);
    body.append("lewati_kembar", lewatiKembar ? "1" : "0");
    try {
      const res = await api<Impor>("/jamaahs/impor", { method: "POST", body });
      setImpor(res.data);
      setHasil(null);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Gagal menyimpan");
    } finally {
      setSibuk(false);
    }
  }

  async function batal() {
    if (!impor) return;
    if (!confirm(`Hapus ${impor.disimpan} jamaah yang baru saja diimpor?`)) return;

    setError("");
    setSibuk(true);
    try {
      await api(`/jamaahs/impor/${impor.impor_id}`, { method: "DELETE" });
      setImpor(null);
      if (fileRef.current) fileRef.current.value = "";
    } catch (err) {
      setError(err instanceof Error ? err.message : "Gagal membatalkan impor");
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
          Isi datanya di templat ini, jangan bikin kolom sendiri. Simpan seperti biasa, lalu
          unggah berkasnya di langkah 2 — tidak perlu diubah jadi format lain.
        </p>
        <div className="mt-3 flex flex-wrap gap-2">
          <button onClick={() => ambilTemplate("xlsx")}
            className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
            Templat Excel (.xlsx)
          </button>
          <button onClick={() => ambilTemplate("csv")}
            className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
            Templat CSV
          </button>
        </div>
        {/* .xlsx menyimpan tanggal sebagai tanggal betulan, CSV cuma sebagai tulisan —
            di situlah 30/11 vs 11/30 jadi soal. */}
        <p className="mt-2 text-xs text-gray-500">
          Pakai .xlsx kalau Anda mengisinya di Excel — tanggal lahirnya terbaca pasti.
          CSV untuk Google Sheets atau LibreOffice.
        </p>

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
            <dt className="text-xs font-medium text-gray-500">Isi kode_keluarga</dt>
            <dd className="text-xs text-gray-800">
              Kode bebas yang <strong>disamakan untuk satu rumah</strong> — mis.{" "}
              <span className="font-mono">KK-SUGENG-01</span>. Satu barisnya diisi{" "}
              <span className="font-mono">status_kk = kepala_keluarga</span>; sisanya otomatis
              tersambung ke dia. Jangan pakai nomor KK 16 digit — yang dibutuhkan cuma
              pengelompokan, bukan nomor identitasnya.
            </dd>
          </div>
          <div>
            <dt className="text-xs font-medium text-gray-500">Isi tanggal_lahir</dt>
            <dd className="text-xs text-gray-800">
              Di .xlsx: ketik biasa, Excel yang mengurus. Di CSV:{" "}
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
          Terima .xlsx dan .csv, maksimal 2000 baris per file; kalau lebih, pecah per desa.
          Format .xls lama (Excel 2003) belum didukung.
        </p>
        <div className="mt-3 flex flex-wrap items-center gap-2">
          <input ref={fileRef} type="file" accept=".xlsx,.csv" aria-label="File .xlsx atau CSV"
            onChange={() => { setHasil(null); setImpor(null); setError(""); }}
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

          <div className="mt-4 border-t border-gray-100 pt-4">
            <h3 className="font-semibold text-gray-900">3. Simpan ke database</h3>
            {hasil.ringkasan.error > 0 ? (
              <p className="mt-1 text-sm text-gray-600">
                Masih ada baris error — impor tidak dijalankan sebagian, jadi perbaiki dulu semuanya.
              </p>
            ) : (
              <>
                {hasil.ringkasan.kembar > 0 && (
                  <label className="mt-2 flex items-start gap-2 text-sm text-gray-700">
                    <input type="checkbox" className="mt-0.5" checked={lewatiKembar}
                      onChange={(e) => setLewatiKembar(e.target.checked)} />
                    <span>
                      Lewati {hasil.ringkasan.kembar} nama yang sudah ada di kelompoknya.
                      <span className="block text-xs text-gray-500">
                        Matikan kalau memang ada dua orang berbeda yang kebetulan senama.
                      </span>
                    </span>
                  </label>
                )}
                <button onClick={simpan} disabled={sibuk}
                  className="mt-3 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50">
                  {sibuk ? "Menyimpan..." : `Impor ${hasil.ringkasan.total - (lewatiKembar ? hasil.ringkasan.kembar : 0)} jamaah`}
                </button>
              </>
            )}
          </div>
        </div>
      )}

      {impor && (
        <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-4">
          <h3 className="font-semibold text-emerald-900">
            {impor.disimpan} jamaah tersimpan
            {impor.dilewati > 0 && `, ${impor.dilewati} dilewati karena namanya sudah ada`}
          </h3>
          <p className="mt-1 text-sm text-emerald-800">
            Salah file? Batalkan sekarang — seluruh impor ini dihapus sekaligus. Setelah ada
            yang diabsen atau difoto, pembatalan sekaligus tidak bisa lagi.
          </p>
          <div className="mt-3 flex flex-wrap gap-2">
            <Link href="/jamaah"
              className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
              Lihat data jamaah
            </Link>
            <button onClick={batal} disabled={sibuk}
              className="rounded-lg border border-red-300 px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50 disabled:opacity-50">
              {sibuk ? "Membatalkan..." : "Batal impor ini"}
            </button>
          </div>
          <p className="mt-2 font-mono text-xs text-emerald-700">impor_id: {impor.impor_id}</p>
        </div>
      )}
    </div>
  );
}
