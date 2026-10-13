"use client";

import { useMemo, useState } from "react";
import { api } from "@/lib/api";
import { JENIS_PENGAJIAN, KATEGORI_USIA, URUTAN_REKAP } from "@/lib/labels";

interface RekapKegiatan { id: number; nama: string; tanggal: string; jenis_pengajian: string }
interface RekapRow {
  jamaah: { id: number; nama_lengkap: string; kelompok: string | null; kategori_usia: string };
  statuses: Record<string, string | null>;
  perlu_perhatian: boolean;
}

const SEL: Record<string, string> = {
  hadir: "bg-emerald-50 text-emerald-700",
  izin: "bg-amber-50 text-amber-700",
  alpha: "bg-red-50 text-red-700",
};
const SINGKAT: Record<string, string> = { hadir: "H", izin: "I", alpha: "A" };

/** Tanpa penanda ini Excel membaca CSV UTF-8 sebagai Latin-1 dan nama berhuruf khusus jadi rusak. */
const BOM = String.fromCharCode(0xfeff);

/**
 * Judul kolom kegiatan: tanggal pendek plus jenis pengajiannya.
 *
 * Lebar kolom cetakan ditentukan isi terpanjang, dan yang terpanjang justru judulnya —
 * "2026-09-18 PENGAJIAN IBU2 PENGURUS TINGKAT DESA" membuat kolom tetap melebar walau
 * selnya cuma berisi "A". Jenis pengajiannya tetap dibawa supaya dua kegiatan di tanggal
 * yang sama masih bisa dibedakan.
 */
function judulKegiatan(k: RekapKegiatan): string {
  return `${k.tanggal.slice(8, 10)}/${k.tanggal.slice(5, 7)} ${JENIS_PENGAJIAN[k.jenis_pengajian] ?? k.jenis_pengajian}`;
}

export default function RekapPage() {
  const now = new Date();
  const bulan = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}`;
  const awalBulan = `${bulan}-01`;
  // Pakai tanggal setempat, bukan toISOString() yang UTC: sebelum pukul 07.00 WIB tanggalnya
  // masih kemarin, dan tiap tanggal 1 rentang bawaannya jadi terbalik ("sampai" mendahului
  // "dari") sehingga halaman langsung ditolak server.
  const hariIni = `${bulan}-${String(now.getDate()).padStart(2, "0")}`;

  const [dari, setDari] = useState(awalBulan);
  const [sampai, setSampai] = useState(hariIni);
  const [jenis, setJenis] = useState("");
  const [kegiatans, setKegiatans] = useState<RekapKegiatan[]>([]);
  const [rows, setRows] = useState<RekapRow[]>([]);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

  async function muat() {
    setLoading(true);
    setError("");
    try {
      const params = new URLSearchParams({ dari, sampai });
      if (jenis) params.set("jenis_pengajian", jenis);
      const res = await api<{ kegiatans: RekapKegiatan[]; rows: RekapRow[] }>(`/rekap?${params}`);
      setKegiatans(res.data.kegiatans);
      setRows(res.data.rows);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Gagal memuat");
    } finally {
      setLoading(false);
    }
  }

  /**
   * Baris laporan: dikelompokkan per kategori usia, lengkap dengan jumlah H/I/A.
   *
   * Dihitung sekali di sini supaya tabel di layar dan berkas unduhannya memakai urutan
   * dan angka yang sama persis — mencocokkan cetakan dengan layar yang urutannya berbeda
   * itu pekerjaan yang tidak perlu ada.
   */
  const baris = useMemo(() => {
    // Kategori di luar daftar jatuh ke paling bawah, bukan menyelip di tengah tanpa alasan.
    const urutan = (kategori: string) => {
      const i = URUTAN_REKAP.indexOf(kategori);

      return i === -1 ? URUTAN_REKAP.length : i;
    };

    return rows
      .map((r) => {
        const terisi = Object.values(r.statuses).filter(Boolean);

        return {
          ...r,
          jumlah: {
            hadir: terisi.filter((s) => s === "hadir").length,
            izin: terisi.filter((s) => s === "izin").length,
            alpha: terisi.filter((s) => s === "alpha").length,
          },
        };
      })
      .sort((a, b) =>
        urutan(a.jamaah.kategori_usia) - urutan(b.jamaah.kategori_usia) ||
        a.jamaah.nama_lengkap.localeCompare(b.jamaah.nama_lengkap)
      );
  }, [rows]);

  /**
   * CSV dirakit di browser dari tabel yang sudah tampil: tidak ada endpoint baru, dan
   * unduhannya tidak perlu menyelipkan token ke URL supaya bisa dibuka tanpa header.
   */
  function unduhCsv() {
    const judul = [
      "Nama", "Kelompok", "Kategori",
      ...kegiatans.map(judulKegiatan),
      "H", "I", "A", "Perlu Perhatian",
    ];

    const isi = baris.map((r) => [
      r.jamaah.nama_lengkap,
      r.jamaah.kelompok ?? "",
      KATEGORI_USIA[r.jamaah.kategori_usia] ?? r.jamaah.kategori_usia,
      // sel kosong = bukan peserta kegiatan itu, bukan berarti tidak hadir
      ...kegiatans.map((k) => SINGKAT[r.statuses[k.id] ?? ""] ?? ""),
      String(r.jumlah.hadir),
      String(r.jumlah.izin),
      String(r.jumlah.alpha),
      r.perlu_perhatian ? "Ya" : "",
    ]);

    // Baris "sep=," memberi tahu Excel untuk memakai koma walau setelan wilayahnya
    // (termasuk Indonesia) memakai titik koma — tanpa itu seluruh baris masuk satu kolom.
    const csv = "sep=,\r\n" + [judul, ...isi]
      .map((kolom) => kolom.map((sel) => `"${sel.replaceAll('"', '""')}"`).join(","))
      .join("\r\n");

    const url = URL.createObjectURL(new Blob([BOM + csv], { type: "text/csv;charset=utf-8" }));
    const tautan = document.createElement("a");
    tautan.href = url;
    tautan.download = `rekap-${dari}-sd-${sampai}.csv`;
    tautan.click();
    URL.revokeObjectURL(url);
  }

  const input = "w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none";

  return (
    <div className="space-y-4">
      <h2 className="text-xl font-bold text-gray-900">Rekapitulasi Absensi</h2>

      <div className="flex flex-wrap items-end gap-3">
        <div className="min-w-0 w-full sm:w-40">
          <label className="mb-1 block text-xs font-medium text-gray-600" htmlFor="rk-dari">Dari</label>
          <input id="rk-dari" type="date" className={input} value={dari} onChange={(e) => setDari(e.target.value)} />
        </div>
        <div className="min-w-0 w-full sm:w-40">
          <label className="mb-1 block text-xs font-medium text-gray-600" htmlFor="rk-sampai">Sampai</label>
          <input id="rk-sampai" type="date" className={input} value={sampai} onChange={(e) => setSampai(e.target.value)} />
        </div>
        <div className="min-w-0 w-full sm:w-48">
          <label className="mb-1 block text-xs font-medium text-gray-600" htmlFor="rk-jenis">Jenis Pengajian</label>
          <select id="rk-jenis" className={input} value={jenis} onChange={(e) => setJenis(e.target.value)}>
            <option value="">Semua</option>
            {Object.entries(JENIS_PENGAJIAN).map(([v, l]) => (
              <option key={v} value={v}>{l}</option>
            ))}
          </select>
        </div>
        <button onClick={muat} disabled={loading}
          className="w-full rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50 sm:w-auto">
          {loading ? "Memuat..." : "Tampilkan"}
        </button>
        {kegiatans.length > 0 && (
          <button onClick={unduhCsv}
            className="w-full rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 sm:w-auto">
            Unduh CSV
          </button>
        )}
      </div>

      {error && <p className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}

      {kegiatans.length > 0 && (
        <>
          <p className="text-xs text-gray-500">
            H = Hadir · I = Izin · A = Alpha · <span className="font-semibold text-red-600">★</span> = 3+ alpha berturut-turut ·
            tiga kolom terakhir = jumlahnya, dihitung dari kegiatan yang dia jadi pesertanya
          </p>
          <div className="overflow-x-auto rounded-xl border border-gray-200 bg-white">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 text-left text-xs text-gray-500">
                <tr>
                  <th className="sticky left-0 bg-gray-50 p-2 sm:p-3">Nama</th>
                  <th className="hidden p-2 sm:table-cell sm:p-3">Kelompok</th>
                  <th className="hidden p-2 sm:table-cell sm:p-3">Kategori</th>
                  {kegiatans.map((k) => (
                    <th key={k.id} className="whitespace-nowrap p-2 text-center sm:p-3" title={k.nama}>
                      {k.tanggal.slice(5)}
                    </th>
                  ))}
                  {/* Angka ringkasan dipisah garis supaya tidak terbaca sebagai kegiatan berikutnya. */}
                  <th className="border-l border-gray-200 p-2 text-center sm:p-3">H</th>
                  <th className="p-2 text-center sm:p-3">I</th>
                  <th className="p-2 text-center sm:p-3">A</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {baris.map((r) => (
                  <tr key={r.jamaah.id} className={r.perlu_perhatian ? "bg-red-50/50" : ""}>
                    <td className="sticky left-0 bg-inherit p-2 font-medium text-gray-900 sm:p-3">
                      {r.perlu_perhatian && <span className="mr-1 text-red-600">★</span>}
                      {r.jamaah.nama_lengkap}
                    </td>
                    <td className="hidden p-2 text-gray-500 sm:table-cell sm:p-3">{r.jamaah.kelompok}</td>
                    <td className="hidden whitespace-nowrap p-2 text-gray-500 sm:table-cell sm:p-3">
                      {KATEGORI_USIA[r.jamaah.kategori_usia] ?? r.jamaah.kategori_usia}
                    </td>
                    {kegiatans.map((k) => {
                      const s = r.statuses[k.id];
                      return (
                        <td key={k.id} className="p-1 text-center">
                          {s ? (
                            <span className={`inline-block w-6 rounded py-0.5 text-xs font-semibold ${SEL[s]}`}>
                              {SINGKAT[s]}
                            </span>
                          ) : (
                            <span className="text-gray-300">·</span>
                          )}
                        </td>
                      );
                    })}
                    <td className="border-l border-gray-200 p-2 text-center font-semibold text-emerald-700 sm:p-3">
                      {r.jumlah.hadir}
                    </td>
                    <td className="p-2 text-center text-amber-700 sm:p-3">{r.jumlah.izin}</td>
                    <td className="p-2 text-center font-semibold text-red-700 sm:p-3">{r.jumlah.alpha}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}

      {!loading && kegiatans.length === 0 && (
        <p className="text-sm text-gray-400">Pilih rentang tanggal lalu klik Tampilkan.</p>
      )}
    </div>
  );
}
