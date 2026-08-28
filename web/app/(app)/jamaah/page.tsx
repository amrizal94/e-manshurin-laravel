"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import Link from "next/link";
import { api } from "@/lib/api";
import { KATEGORI_USIA, STATUS_KK } from "@/lib/labels";
import { Pagination } from "@/components/Pagination";
import { useRoleGuard } from "@/lib/useRoleGuard";

interface Kelompok { id: number; nama: string; desa?: { nama: string } }
interface Jamaah {
  id: number;
  nama_lengkap: string;
  nama_panggilan: string | null;
  jenis_kelamin: "L" | "P";
  tempat_lahir: string | null;
  tanggal_lahir: string | null;
  usia: number | null;
  alamat: string | null;
  no_hp: string | null;
  kelompok_id: number;
  kelompok?: { nama: string; desa?: { nama: string } };
  kategori_usia: string;
  pekerjaan: string | null;
  status_mubaligh: boolean;
  status_kk: string | null;
  kode_keluarga: string | null;
  kepala_keluarga_id: number | null;
  aktif: boolean;
  keterangan_tidak_aktif: string | null;
  photos_count?: number;
}

interface Keluarga {
  kunci: string;
  kode_keluarga: string | null;
  kepala_keluarga_id: number | null;
  kelompok_id: number;
  anggota: Jamaah[];
  masalah: string[];
}

type Mode = "orang" | "keluarga";

const PER_PAGE = 25;

/**
 * Mode daftar yang terakhir dipakai. Melengkapi data KK itu pekerjaan berjam-jam;
 * kembali ke mode per orang tiap kali halaman dibuka bikin pekerjaan itu dimulai
 * ulang terus. Di browser, bukan di database — ini preferensi alat kerja.
 */
const MODE = "jamaah-mode-daftar";

function bacaMode(): Mode {
  if (typeof window === "undefined") return "orang";

  return localStorage.getItem(MODE) === "keluarga" ? "keluarga" : "orang";
}

/**
 * Kelompok dan kategori yang terakhir dipakai menambah jamaah. Petugas biasanya
 * mendata satu kelompok berturut-turut, dan form yang selalu mulai dari kelompok
 * pertama menurut abjad bukan cuma merepotkan: yang lupa menggantinya menyimpan
 * jamaah ke kelompok yang salah tanpa peringatan.
 *
 * Disimpan di browser, bukan di database — ini preferensi alat kerja, dan satu akun
 * bisa dipakai bergantian di beberapa perangkat.
 */
const TERAKHIR = "jamaah-input-terakhir";

function bacaTerakhir(): { kelompok_id?: number; kategori_usia?: string } {
  if (typeof window === "undefined") return {};
  try {
    return JSON.parse(localStorage.getItem(TERAKHIR) ?? "{}");
  } catch {
    return {};
  }
}

const KOSONG = {
  nama_lengkap: "", nama_panggilan: "", jenis_kelamin: "L", tempat_lahir: "",
  tanggal_lahir: "", alamat: "", no_hp: "", kelompok_id: 0, kategori_usia: "remaja",
  pekerjaan: "", status_mubaligh: false, status_kk: "", kode_keluarga: "",
  kepala_keluarga_id: "" as number | "", aktif: true, keterangan_tidak_aktif: "",
};

export default function JamaahPage() {
  useRoleGuard(["super_admin", "admin"]);
  const [rows, setRows] = useState<Jamaah[]>([]);
  const [keluargas, setKeluargas] = useState<Keluarga[]>([]);
  const [mode, setMode] = useState<Mode>("orang");
  const [tanpaKeluarga, setTanpaKeluarga] = useState(false);
  const [belumMasuk, setBelumMasuk] = useState(0);
  const [kepalaKeluargas, setKepalaKeluargas] = useState<Jamaah[]>([]);
  const [kelompoks, setKelompoks] = useState<Kelompok[]>([]);
  const [search, setSearch] = useState("");
  const [searchDebounced, setSearchDebounced] = useState("");
  const [filterKelompok, setFilterKelompok] = useState("");
  const [filterKategori, setFilterKategori] = useState("");
  // Disaring lewat satu jamaah, bukan lewat kodenya: keluarga yang datanya dari form
  // lama belum punya kode, dan server yang memutuskan sisi mana yang dipakai.
  const [keluarga, setKeluarga] = useState<{ id: number; nama: string } | null>(null);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(true);
  const [form, setForm] = useState<typeof KOSONG | null>(null);
  const [tersimpan, setTersimpan] = useState("");
  const namaRef = useRef<HTMLInputElement>(null);
  const [editId, setEditId] = useState<number | null>(null);

  useEffect(() => {
    const t = setTimeout(() => {
      setSearchDebounced(search);
      setPage(1);
    }, 300);
    return () => clearTimeout(t);
  }, [search]);

  const reload = useCallback(() => {
    const params = new URLSearchParams({ page: String(page) });
    if (searchDebounced) params.set("search", searchDebounced);
    if (filterKelompok) params.set("kelompok_id", filterKelompok);
    Promise.resolve().then(() => setLoading(true)); // defer 1 microtask: react-hooks/set-state-in-effect gak suka setState sinkron di body effect

    if (mode === "keluarga") {
      if (tanpaKeluarga) params.set("tanpa_keluarga", "1");
      api<{ data: Keluarga[]; last_page: number; total: number; belum_masuk_keluarga: number }>(`/jamaahs/keluarga?${params}`)
        .then((res) => {
          setKeluargas(res.data.data);
          setLastPage(res.data.last_page);
          setTotal(res.data.total);
          setBelumMasuk(res.data.belum_masuk_keluarga);
          if (page > res.data.last_page) setPage(res.data.last_page);
        })
        .catch((err) => setError(err.message))
        .finally(() => setLoading(false));

      return;
    }

    if (filterKategori) params.set("kategori_usia", filterKategori);
    if (keluarga) params.set("keluarga_id", String(keluarga.id));
    api<{ data: Jamaah[]; last_page: number; total: number }>(`/jamaahs?${params}`)
      .then((res) => {
        setRows(res.data.data);
        setLastPage(res.data.last_page);
        setTotal(res.data.total);
        // Menghapus baris terakhir di halaman terakhir menyisakan nomor halaman yang
        // sudah tidak ada isinya — tampak seperti data habis. Mundur ke halaman terakhir.
        if (page > res.data.last_page) setPage(res.data.last_page);
      })
      .catch((err) => setError(err.message))
      .finally(() => setLoading(false));
  }, [mode, tanpaKeluarga, searchDebounced, filterKelompok, filterKategori, keluarga, page]);

  // Daftar kepala keluarga harus lepas dari pagination dan filter tabel: kalau diambil dari
  // `rows`, KK yang kebetulan ada di halaman lain jadi tidak bisa dipilih — dan jamaah yang
  // sudah tersambung terlihat seolah kehilangan kepala keluarganya.
  // ponytail: sekali ambil semua, bukan select yang bisa dicari. Ganti kalau KK > 1000.
  const muatKepalaKeluarga = useCallback(() => {
    api<{ data: Jamaah[] }>("/jamaahs?status_kk=kepala_keluarga&per_page=1000")
      .then((res) => setKepalaKeluargas(res.data.data))
      .catch(() => {});
  }, []);

  useEffect(() => {
    Promise.resolve().then(() => setMode(bacaMode()));
  }, []);

  useEffect(reload, [reload]);
  useEffect(muatKepalaKeluarga, [muatKepalaKeluarga]);
  useEffect(() => {
    api<Kelompok[]>("/kelompoks").then((res) => setKelompoks(res.data)).catch(() => {});
  }, []);

  function ubahFilterKelompok(v: string) {
    setFilterKelompok(v);
    setPage(1);
  }

  function ubahFilterKategori(v: string) {
    setFilterKategori(v);
    setPage(1);
  }

  function ubahMode(v: Mode) {
    setMode(v);
    setPage(1);
    setKeluarga(null); // penyaring satu keluarga tidak ada artinya di tampilan per keluarga
    localStorage.setItem(MODE, v);
  }

  /**
   * Membuka form tambah dengan kode keluarga, kepala keluarga, dan kelompoknya sudah
   * terisi — itu inti pekerjaannya, bukan mengetik ulang tiga isian yang sudah jelas.
   * Status KK ditebak "anak" karena itu yang paling sering ditambahkan; tetap bisa diganti.
   */
  function tambahAnggota(k: Keluarga) {
    setEditId(null);
    setTersimpan("");
    setForm({
      ...bawaanBaru(),
      kelompok_id: k.kelompok_id,
      kode_keluarga: k.kode_keluarga ?? "",
      kepala_keluarga_id: k.kepala_keluarga_id ?? "",
      status_kk: "anak",
    });
  }

  function lihatKeluarga(j: Jamaah) {
    setKeluarga({ id: j.id, nama: j.nama_lengkap });
    setPage(1);
  }

  function buka(j?: Jamaah) {
    setEditId(j?.id ?? null);
    setTersimpan("");
    setForm(j ? {
      nama_lengkap: j.nama_lengkap, nama_panggilan: j.nama_panggilan ?? "",
      jenis_kelamin: j.jenis_kelamin, tempat_lahir: j.tempat_lahir ?? "",
      tanggal_lahir: j.tanggal_lahir?.slice(0, 10) ?? "", alamat: j.alamat ?? "",
      no_hp: j.no_hp ?? "", kelompok_id: j.kelompok_id, kategori_usia: j.kategori_usia,
      pekerjaan: j.pekerjaan ?? "", status_mubaligh: j.status_mubaligh,
      status_kk: j.status_kk ?? "", kode_keluarga: j.kode_keluarga ?? "",
      kepala_keluarga_id: j.kepala_keluarga_id ?? "",
      aktif: j.aktif, keterangan_tidak_aktif: j.keterangan_tidak_aktif ?? "",
    } : bawaanBaru());
  }

  /** Filter tabel menang atas ingatan: daftar yang sedang disaring ke satu kelompok
   *  adalah niat yang paling jelas saat itu juga. */
  function bawaanBaru(): typeof KOSONG {
    const terakhir = bacaTerakhir();

    return {
      ...KOSONG,
      kelompok_id: Number(filterKelompok) || terakhir.kelompok_id || kelompoks[0]?.id || 0,
      kategori_usia: filterKategori || terakhir.kategori_usia || KOSONG.kategori_usia,
    };
  }

  /**
   * Peringatan, bukan larangan. Dua orang memang boleh senama — di data yang ada pun
   * sudah ada beberapa pasang — tapi satu orang jangan sampai didaftarkan dua kali.
   * Jadi petugas dikonfirmasi dulu dan tetap boleh melanjutkan.
   */
  async function kembarDiabaikan(): Promise<boolean> {
    if (!form) return true;
    const nama = form.nama_lengkap.trim();
    let kembar: Jamaah[] = [];
    try {
      const res = await api<{ data: Jamaah[] }>(`/jamaahs?search=${encodeURIComponent(nama)}&per_page=100`);
      kembar = res.data.data.filter(
        (j) => j.id !== editId && j.nama_lengkap.trim().toLowerCase() === nama.toLowerCase()
      );
    } catch {
      return true; // gagal memeriksa jangan sampai menghalangi penyimpanan
    }

    if (kembar.length === 0) return true;

    const dimana = kembar.map((j) => j.kelompok?.nama ?? "kelompok lain").join(", ");

    return confirm(`Sudah ada jamaah bernama "${nama}" di ${dimana}.\n\nYakin ini orang yang berbeda?`);
  }

  async function simpan(e: React.SyntheticEvent, lanjut = false) {
    e.preventDefault();
    if (!form) return;
    setError("");
    setTersimpan("");
    if (!(await kembarDiabaikan())) return;
    const body = JSON.stringify({
      ...form,
      nama_panggilan: form.nama_panggilan || null,
      tempat_lahir: form.tempat_lahir || null,
      tanggal_lahir: form.tanggal_lahir || null,
      alamat: form.alamat || null,
      no_hp: form.no_hp || null,
      pekerjaan: form.pekerjaan || null,
      status_kk: form.status_kk || null,
      kode_keluarga: form.kode_keluarga || null,
      kepala_keluarga_id: form.kepala_keluarga_id || null,
      keterangan_tidak_aktif: form.keterangan_tidak_aktif || null,
    });
    try {
      await api(editId ? `/jamaahs/${editId}` : "/jamaahs", {
        method: editId ? "PUT" : "POST",
        body,
      });
      // Yang diingat cuma dari penambahan: mengedit jamaah lama bisa dari kelompok
      // mana saja, dan itu tidak boleh menggeser bawaan untuk data berikutnya.
      if (!editId) {
        localStorage.setItem(
          TERAKHIR,
          JSON.stringify({ kelompok_id: form.kelompok_id, kategori_usia: form.kategori_usia })
        );
      }

      if (lanjut) {
        setTersimpan(`"${form.nama_lengkap}" tersimpan.`);
        setForm({ ...bawaanBaru(), kelompok_id: form.kelompok_id, kategori_usia: form.kategori_usia });
        namaRef.current?.focus();
      } else {
        setForm(null);
      }
      reload();
      muatKepalaKeluarga(); // KK baru harus langsung bisa dipilih tanpa refresh halaman
    } catch (err) {
      setError(err instanceof Error ? err.message : "Gagal menyimpan");
    }
  }

  async function hapus(j: Jamaah) {
    if (!confirm(`Hapus jamaah "${j.nama_lengkap}"?`)) return;
    try {
      await api(`/jamaahs/${j.id}`, { method: "DELETE" });
      reload();
      muatKepalaKeluarga();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Gagal menghapus");
    }
  }

  const input = "w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none";
  const label = "mb-1 block text-xs font-medium text-gray-600";

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h2 className="text-xl font-bold text-gray-900">Data Jamaah</h2>
        <div className="flex gap-2">
          <Link
            href="/jamaah/impor"
            className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"
          >Impor CSV</Link>
          <button
            onClick={() => buka()}
            className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700"
          >+ Tambah Jamaah</button>
        </div>
      </div>

      {error && <p className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}

      <div className="inline-flex rounded-lg border border-gray-300 p-0.5">
        {([["orang", "Per Orang"], ["keluarga", "Per Keluarga"]] as [Mode, string][]).map(([v, l]) => (
          <button key={v} onClick={() => ubahMode(v)} aria-pressed={mode === v}
            className={`rounded-md px-3 py-1.5 text-sm ${mode === v
              ? "bg-emerald-600 font-semibold text-white"
              : "text-gray-600 hover:bg-gray-50"}`}>{l}</button>
        ))}
      </div>

      <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
        <input
          placeholder="Cari nama atau kode keluarga..."
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none sm:w-64"
        />
        <select
          aria-label="Filter kelompok"
          value={filterKelompok}
          onChange={(e) => ubahFilterKelompok(e.target.value)}
          className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none sm:w-48"
        >
          <option value="">Semua Kelompok</option>
          {kelompoks.map((k) => (
            <option key={k.id} value={k.id}>{k.nama}{k.desa ? ` — ${k.desa.nama}` : ""}</option>
          ))}
        </select>
        {mode === "orang" && (
          <select
            aria-label="Filter kategori usia"
            value={filterKategori}
            onChange={(e) => ubahFilterKategori(e.target.value)}
            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none sm:w-48"
          >
            <option value="">Semua Kategori</option>
            {Object.entries(KATEGORI_USIA).map(([v, l]) => (
              <option key={v} value={v}>{l}</option>
            ))}
          </select>
        )}
        {mode === "keluarga" && (
          <button
            onClick={() => { setTanpaKeluarga(!tanpaKeluarga); setPage(1); }}
            aria-pressed={tanpaKeluarga}
            className={`rounded-lg border px-3 py-2 text-sm ${tanpaKeluarga
              ? "border-amber-500 bg-amber-50 font-semibold text-amber-900"
              : "border-gray-300 text-gray-700 hover:bg-gray-50"}`}>
            Belum masuk keluarga ({belumMasuk})
          </button>
        )}
      </div>

      {keluarga && (
        <div className="flex items-center gap-2 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
          <span>Menampilkan satu keluarga: <strong>{keluarga.nama}</strong></span>
          <button onClick={() => { setKeluarga(null); setPage(1); }}
            className="ml-auto font-semibold hover:underline">Tampilkan semua</button>
        </div>
      )}

      {mode === "keluarga" && (
        <div className="space-y-3">
          {loading && (
            <p className="rounded-xl border border-gray-200 bg-white p-6 text-center text-gray-400">Memuat...</p>
          )}
          {!loading && keluargas.length === 0 && (
            <p className="rounded-xl border border-gray-200 bg-white p-6 text-center text-gray-400">
              {tanpaKeluarga ? "Semua jamaah sudah masuk keluarga" : "Belum ada data"}
            </p>
          )}
          {keluargas.map((k) => (
            <div key={k.kunci} className="rounded-xl border border-gray-200 bg-white p-4">
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0">
                  <p className="font-medium text-gray-900">
                    {k.kode_keluarga ?? k.anggota[0]?.nama_lengkap}
                    {!k.kode_keluarga && <span className="ml-2 text-xs font-normal text-gray-400">tanpa kode keluarga</span>}
                  </p>
                  <p className="text-xs text-gray-400">
                    {k.anggota.length} anggota · {k.anggota[0]?.kelompok?.nama}
                    {k.anggota[0]?.kelompok?.desa && ` — ${k.anggota[0].kelompok.desa.nama}`}
                  </p>
                </div>
                <button onClick={() => tambahAnggota(k)}
                  className="shrink-0 rounded-lg border border-emerald-600 px-3 py-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-50">
                  + Tambah anggota
                </button>
              </div>

              {k.masalah.map((m) => (
                <p key={m} className="mt-2 rounded bg-amber-50 px-2 py-1 text-xs text-amber-900">{m}</p>
              ))}

              <ul className="mt-3 divide-y divide-gray-100 border-t border-gray-100">
                {k.anggota.map((j) => (
                  <li key={j.id} className="flex flex-wrap items-center gap-x-3 gap-y-1 py-2 text-sm">
                    <span className="font-medium text-gray-900">{j.nama_lengkap}</span>
                    <span className={`rounded px-2 py-0.5 text-xs ${j.status_kk
                      ? "bg-gray-100 text-gray-600"
                      : "bg-amber-50 text-amber-800"}`}>
                      {j.status_kk ? STATUS_KK[j.status_kk] : "status KK belum diisi"}
                    </span>
                    <span className="text-xs text-gray-400">
                      {j.jenis_kelamin} · {j.usia ?? "-"} th · {KATEGORI_USIA[j.kategori_usia]}
                    </span>
                    <span className="ml-auto flex gap-3 text-xs">
                      <Link href={`/jamaah/${j.id}/wajah`} className="font-semibold text-emerald-600 hover:text-emerald-800">Wajah</Link>
                      <button onClick={() => buka(j)} className="text-gray-400 hover:text-gray-700">Edit</button>
                      <button onClick={() => hapus(j)} className="text-red-400 hover:text-red-700">Hapus</button>
                    </span>
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </div>
      )}

      {mode === "orang" && (
      <>
      <div className="space-y-2 sm:hidden">
        {loading && (
          <p className="rounded-xl border border-gray-200 bg-white p-6 text-center text-gray-400">Memuat...</p>
        )}
        {!loading && rows.length === 0 && (
          <p className="rounded-xl border border-gray-200 bg-white p-6 text-center text-gray-400">Belum ada data</p>
        )}
        {rows.map((j, i) => (
          <div key={j.id} className="rounded-xl border border-gray-200 bg-white p-3">
            <div className="flex items-start justify-between gap-2">
              <div className="min-w-0">
                <p className="truncate font-medium text-gray-900">
                  {(page - 1) * PER_PAGE + i + 1}. {j.nama_lengkap}
                </p>
                <p className="text-xs text-gray-500">
                  {j.jenis_kelamin} · {j.usia ?? "-"} th · {KATEGORI_USIA[j.kategori_usia]}
                </p>
                <p className="text-xs text-gray-400">
                  {j.kelompok?.nama}
                  {j.kelompok?.desa && ` — ${j.kelompok.desa.nama}`}
                </p>
              </div>
              {j.aktif ? (
                <span className="shrink-0 rounded bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700">Aktif</span>
              ) : (
                <span className="shrink-0 rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-500" title={j.keterangan_tidak_aktif ?? ""}>Tidak Aktif</span>
              )}
            </div>
            <div className="mt-2 flex items-center justify-between border-t border-gray-100 pt-2 text-xs">
              <span className="text-gray-400">Foto: {j.photos_count ?? 0}</span>
              <div className="flex gap-3">
                <button onClick={() => lihatKeluarga(j)} className="text-gray-400 hover:text-gray-700">Keluarga</button>
                <Link href={`/jamaah/${j.id}/wajah`} className="font-semibold text-emerald-600 hover:text-emerald-800">Wajah</Link>
                <button onClick={() => buka(j)} className="text-gray-400 hover:text-gray-700">Edit</button>
                <button onClick={() => hapus(j)} className="text-red-400 hover:text-red-700">Hapus</button>
              </div>
            </div>
          </div>
        ))}
      </div>

      <div className="hidden overflow-x-auto rounded-xl border border-gray-200 bg-white sm:block">
        <table className="w-full text-sm">
          <thead className="bg-gray-50 text-left text-xs text-gray-500">
            <tr>
              <th className="p-3">No</th>
              <th className="p-3">Nama</th>
              <th className="p-3">L/P</th>
              <th className="p-3">Usia</th>
              <th className="p-3">Kategori</th>
              <th className="p-3">Kelompok</th>
              <th className="p-3">Foto</th>
              <th className="p-3">Status</th>
              <th className="p-3"></th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {rows.map((j, i) => (
              <tr key={j.id}>
                <td className="p-3 text-gray-400">{(page - 1) * PER_PAGE + i + 1}</td>
                <td className="p-3 font-medium text-gray-900">{j.nama_lengkap}</td>
                <td className="p-3">{j.jenis_kelamin}</td>
                <td className="p-3">{j.usia ?? "-"}</td>
                <td className="p-3">{KATEGORI_USIA[j.kategori_usia]}</td>
                <td className="p-3">
                  {j.kelompok?.nama}
                  {j.kelompok?.desa && <span className="text-gray-400"> — {j.kelompok.desa.nama}</span>}
                </td>
                <td className="p-3">{j.photos_count ?? 0}</td>
                <td className="p-3">
                  {j.aktif ? (
                    <span className="rounded bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700">Aktif</span>
                  ) : (
                    <span className="rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-500" title={j.keterangan_tidak_aktif ?? ""}>Tidak Aktif</span>
                  )}
                </td>
                <td className="p-3 text-right">
                  <button onClick={() => lihatKeluarga(j)} className="mr-2 text-xs text-gray-400 hover:text-gray-700">Keluarga</button>
                  <Link href={`/jamaah/${j.id}/wajah`} className="mr-2 text-xs font-semibold text-emerald-600 hover:text-emerald-800">Wajah</Link>
                  <button onClick={() => buka(j)} className="mr-2 text-xs text-gray-400 hover:text-gray-700">Edit</button>
                  <button onClick={() => hapus(j)} className="text-xs text-red-400 hover:text-red-700">Hapus</button>
                </td>
              </tr>
            ))}
            {!loading && rows.length === 0 && (
              <tr><td colSpan={9} className="p-6 text-center text-gray-400">Belum ada data</td></tr>
            )}
            {loading && (
              <tr><td colSpan={9} className="p-6 text-center text-gray-400">Memuat...</td></tr>
            )}
          </tbody>
        </table>
      </div>
      </>
      )}

      {total > 0 && (
        <div className="flex items-center justify-between text-sm text-gray-500">
          <span>
            Menampilkan {(page - 1) * PER_PAGE + 1}–{Math.min(page * PER_PAGE, total)} dari {total}{" "}
            {mode === "keluarga" ? "keluarga" : "jamaah"}
          </span>
          <Pagination page={page} lastPage={lastPage} onChange={setPage} />
        </div>
      )}

      {form && (
        <div className="fixed inset-0 z-10 flex items-start justify-center overflow-y-auto bg-black/30 p-4">
          <form onSubmit={simpan} className="my-8 w-full max-w-2xl space-y-4 rounded-xl bg-white p-6 shadow-xl">
            <h3 className="text-lg font-bold text-gray-900">{editId ? "Edit" : "Tambah"} Jamaah</h3>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div className="sm:col-span-2">
                <label className={label} htmlFor="f-nama_lengkap">Nama Lengkap *</label>
                <input id="f-nama_lengkap" ref={namaRef} required className={input} value={form.nama_lengkap}
                  onChange={(e) => setForm({ ...form, nama_lengkap: e.target.value })} />
              </div>
              <div>
                <label className={label} htmlFor="f-nama_panggilan">Nama Panggilan</label>
                <input id="f-nama_panggilan" className={input} value={form.nama_panggilan}
                  onChange={(e) => setForm({ ...form, nama_panggilan: e.target.value })} />
              </div>
              <div>
                <label className={label} htmlFor="f-jenis_kelamin">Jenis Kelamin *</label>
                <select id="f-jenis_kelamin" className={input} value={form.jenis_kelamin}
                  onChange={(e) => setForm({ ...form, jenis_kelamin: e.target.value })}>
                  <option value="L">Laki-laki</option>
                  <option value="P">Perempuan</option>
                </select>
              </div>
              <div>
                <label className={label} htmlFor="f-tempat_lahir">Tempat Lahir</label>
                <input id="f-tempat_lahir" className={input} value={form.tempat_lahir}
                  onChange={(e) => setForm({ ...form, tempat_lahir: e.target.value })} />
              </div>
              <div>
                <label className={label} htmlFor="f-tanggal_lahir">Tanggal Lahir</label>
                <input id="f-tanggal_lahir" type="date" className={input} value={form.tanggal_lahir}
                  onChange={(e) => setForm({ ...form, tanggal_lahir: e.target.value })} />
              </div>
              <div className="sm:col-span-2">
                <label className={label} htmlFor="f-alamat">Alamat</label>
                <textarea id="f-alamat" className={input} rows={2} value={form.alamat}
                  onChange={(e) => setForm({ ...form, alamat: e.target.value })} />
              </div>
              <div>
                <label className={label} htmlFor="f-no_hp">No. HP</label>
                <input id="f-no_hp" className={input} value={form.no_hp}
                  onChange={(e) => setForm({ ...form, no_hp: e.target.value })} />
              </div>
              <div>
                <label className={label} htmlFor="f-kelompok_id">Kelompok *</label>
                <select id="f-kelompok_id" required className={input} value={form.kelompok_id}
                  onChange={(e) => setForm({ ...form, kelompok_id: Number(e.target.value) })}>
                  {kelompoks.map((k) => (
                    <option key={k.id} value={k.id}>{k.nama}{k.desa ? ` — ${k.desa.nama}` : ""}</option>
                  ))}
                </select>
              </div>
              <div>
                <label className={label} htmlFor="f-kategori_usia">Kategori Usia *</label>
                <select id="f-kategori_usia" className={input} value={form.kategori_usia}
                  onChange={(e) => setForm({ ...form, kategori_usia: e.target.value })}>
                  {Object.entries(KATEGORI_USIA).map(([v, l]) => (
                    <option key={v} value={v}>{l}</option>
                  ))}
                </select>
              </div>
              <div>
                <label className={label} htmlFor="f-pekerjaan">Pekerjaan</label>
                <input id="f-pekerjaan" className={input} value={form.pekerjaan}
                  onChange={(e) => setForm({ ...form, pekerjaan: e.target.value })} />
              </div>
              <div>
                <label className={label} htmlFor="f-status_kk">Status KK</label>
                <select id="f-status_kk" className={input} value={form.status_kk}
                  onChange={(e) => setForm({
                    ...form,
                    status_kk: e.target.value,
                    kepala_keluarga_id: e.target.value === "kepala_keluarga" ? "" : form.kepala_keluarga_id,
                  })}>
                  <option value="">-</option>
                  {Object.entries(STATUS_KK).map(([v, l]) => (
                    <option key={v} value={v}>{l}</option>
                  ))}
                </select>
              </div>
              <div>
                <label className={label} htmlFor="f-kode_keluarga">Kode Keluarga</label>
                <input id="f-kode_keluarga" className={input} value={form.kode_keluarga} placeholder="mis. KK-SUGENG-01"
                  onChange={(e) => setForm({ ...form, kode_keluarga: e.target.value })} />
                <p className="mt-1 text-xs text-gray-400">Samakan untuk satu rumah. Dipakai impor untuk menyambungkan keluarga.</p>
              </div>
              <div>
                <label className={label} htmlFor="f-kepala_keluarga_id">Kepala Keluarga</label>
                <select id="f-kepala_keluarga_id" className={input} value={form.kepala_keluarga_id}
                  disabled={form.status_kk === "kepala_keluarga"}
                  onChange={(e) => setForm({ ...form, kepala_keluarga_id: e.target.value ? Number(e.target.value) : "" })}>
                  <option value="">- (bukan anggota keluarga siapa pun)</option>
                  {kepalaKeluargas.filter((r) => r.id !== editId).map((r) => (
                    <option key={r.id} value={r.id}>
                      {r.nama_lengkap}{r.kelompok?.nama ? ` — ${r.kelompok.nama}` : ""}
                    </option>
                  ))}
                </select>
                {form.status_kk === "kepala_keluarga" && (
                  <p className="mt-1 text-xs text-gray-400">Kepala keluarga tidak bisa jadi anggota keluarga lain</p>
                )}
              </div>
              <div className="sm:col-span-2 flex flex-wrap gap-6">
                <label className="flex items-center gap-2 text-sm text-gray-700">
                  <input type="checkbox" checked={form.status_mubaligh}
                    onChange={(e) => setForm({ ...form, status_mubaligh: e.target.checked })} />
                  Mubaligh
                </label>
                <label className="flex items-center gap-2 text-sm text-gray-700">
                  <input type="checkbox" checked={form.aktif}
                    onChange={(e) => setForm({ ...form, aktif: e.target.checked })} />
                  Aktif
                </label>
              </div>
              {!form.aktif && (
                <div className="sm:col-span-2">
                  <label className={label} htmlFor="f-keterangan_tidak_aktif">Keterangan Tidak Aktif</label>
                  <input id="f-keterangan_tidak_aktif" className={input} value={form.keterangan_tidak_aktif}
                    onChange={(e) => setForm({ ...form, keterangan_tidak_aktif: e.target.value })} />
                </div>
              )}
            </div>

            <div className="flex flex-wrap items-center justify-end gap-2">
              {tersimpan && <span className="mr-auto text-sm text-emerald-700">{tersimpan}</span>}
              <button type="button" onClick={() => setForm(null)}
                className="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Batal</button>
              {/* Mendata satu kelompok berarti puluhan kali buka-tutup modal kalau form
                  selalu ditutup. Kelompok dan kategorinya ditahan, namanya dikosongkan. */}
              {!editId && (
                <button type="button" onClick={(e) => {
                  // type=button melewati validasi bawaan form, jadi dipanggil sendiri —
                  // tanpa ini nama yang kosong baru ditolak setelah sampai server.
                  if (e.currentTarget.form?.reportValidity() === false) return;
                  simpan(e, true);
                }}
                  className="rounded-lg border border-emerald-600 px-4 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-50">Simpan &amp; Tambah Lagi</button>
              )}
              <button type="submit"
                className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">Simpan</button>
            </div>
          </form>
        </div>
      )}
    </div>
  );
}
