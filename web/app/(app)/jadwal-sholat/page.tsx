"use client";

import { useCallback, useEffect, useState } from "react";
import { api } from "@/lib/api";
import { jadwalSholat, jam as formatJam } from "@/lib/sholat";
import { useRoleGuard } from "@/lib/useRoleGuard";

interface Opsi { id: number; nama: string }
interface JadwalRutin {
  id: number;
  nama: string;
  sholat: string | null;
  jam_mulai: string;
  jam_selesai: string;
  toleransi_menit: number | null;
  daerah?: Opsi | null;
  desa?: Opsi | null;
  kelompok?: Opsi | null;
}

const SHOLAT = [
  { kunci: "subuh", nama: "Subuh" },
  { kunci: "dzuhur", nama: "Dzuhur" },
  { kunci: "ashar", nama: "Ashar" },
  { kunci: "maghrib", nama: "Maghrib" },
  { kunci: "isya", nama: "Isya" },
] as const;

/** Jamaah sholat sudah berdiri di masjid — tidak ada yang perlu kelonggaran setengah jam. */
const TOLERANSI_BAWAAN = 5;

/** Selisih sebesar ini dari hitungan pantas ditanyakan, bukan langsung disalahkan. */
const AMBANG_PERINGATAN = 30;

interface Baris {
  mulai: string;
  selesai: string;
  toleransi: number | "";
}

const BARIS_KOSONG: Baris = { mulai: "", selesai: "", toleransi: TOLERANSI_BAWAAN };

export default function JadwalSholatPage() {
  useRoleGuard(["super_admin", "admin"]);
  const [jadwals, setJadwals] = useState<JadwalRutin[]>([]);
  const [isi, setIsi] = useState<Record<string, Baris>>({});
  const [target, setTarget] = useState("");
  const [opsi, setOpsi] = useState<{ nilai: string; label: string }[]>([]);
  const [pesan, setPesan] = useState("");
  const [error, setError] = useState("");
  const [menyimpan, setMenyimpan] = useState(false);
  const [loading, setLoading] = useState(true);

  // Hitungan hari ini, cuma sebagai pembanding di sebelah kolom isian. Yang menentukan
  // jam sholat berjamaah tetap pengurus — di pondok sholatnya dimulai manual.
  const [hitungan, setHitungan] = useState<Record<string, string>>({});

  const muat = useCallback(async () => {
    try {
      const [res, koordinat] = await Promise.all([
        api<{ data: JadwalRutin[] }>("/jadwal-rutins?per_page=100"),
        api<{ koordinat?: string }>("/settings").then((r) => r.data.koordinat).catch(() => undefined),
      ]);

      const semua = res.data.data;
      setJadwals(semua);

      const awal: Record<string, Baris> = {};
      for (const s of SHOLAT) {
        const punya = semua.find((j) => j.sholat === s.kunci);
        awal[s.kunci] = punya
          ? {
            mulai: punya.jam_mulai.slice(0, 5),
            selesai: punya.jam_selesai.slice(0, 5),
            toleransi: punya.toleransi_menit ?? TOLERANSI_BAWAAN,
          }
          : BARIS_KOSONG;
      }
      setIsi(awal);

      const pertama = semua.find((j) => j.sholat);
      if (pertama) {
        setTarget(pertama.kelompok ? `kelompok:${pertama.kelompok.id}`
          : pertama.desa ? `desa:${pertama.desa.id}`
            : pertama.daerah ? `daerah:${pertama.daerah.id}` : "");
      }

      const waktu = jadwalSholat(koordinat ?? "-7.8166,112.0114");
      setHitungan(Object.fromEntries(
        waktu.map((w, i) => [SHOLAT[i].kunci, formatJam(w.waktu)])
      ));
    } catch (err) {
      setError(err instanceof Error ? err.message : "Gagal memuat");
    } finally {
      setLoading(false);
    }
  }, []);

  // Ditunda satu microtask: memanggil setState langsung di badan effect memicu render
  // beruntun, dan aturan react-hooks menolaknya.
  useEffect(() => { Promise.resolve().then(muat); }, [muat]);

  useEffect(() => {
    Promise.all([
      api<Opsi[]>("/daerahs").then((r) => r.data.map((d) => ({ nilai: `daerah:${d.id}`, label: `Daerah ${d.nama}` }))).catch(() => []),
      api<Opsi[]>("/desas").then((r) => r.data.map((d) => ({ nilai: `desa:${d.id}`, label: `Desa ${d.nama}` }))).catch(() => []),
      api<Opsi[]>("/kelompoks").then((r) => r.data.map((d) => ({ nilai: `kelompok:${d.id}`, label: `Kelompok ${d.nama}` }))).catch(() => []),
    ]).then((semua) => setOpsi(semua.flat()));
  }, []);

  function isiDariHitungan() {
    setIsi((lama) => Object.fromEntries(
      SHOLAT.map((s) => {
        const mulai = hitungan[s.kunci] ?? lama[s.kunci]?.mulai ?? "";
        return [s.kunci, { ...lama[s.kunci], mulai, selesai: tambahMenit(mulai, 30) }];
      })
    ));
  }

  async function simpan() {
    setError("");
    setPesan("");
    setMenyimpan(true);

    const [level, id] = target.split(":");
    const kabar: string[] = [];

    try {
      for (const s of SHOLAT) {
        const baris = isi[s.kunci];
        if (!baris?.mulai || !baris?.selesai) continue;

        const muatan = {
          nama: `Sholat ${s.nama}`,
          jenis_pengajian: "umum",
          daerah_id: level === "daerah" ? Number(id) : null,
          desa_id: level === "desa" ? Number(id) : null,
          kelompok_id: level === "kelompok" ? Number(id) : null,
          // Tiap hari: sholat berjamaah tidak mengenal hari libur.
          hari: [0, 1, 2, 3, 4, 5, 6],
          jam_mulai: baris.mulai,
          jam_selesai: baris.selesai,
          sholat: s.kunci,
          toleransi_menit: baris.toleransi === "" ? TOLERANSI_BAWAAN : baris.toleransi,
          aktif: true,
        };

        const punya = jadwals.find((j) => j.sholat === s.kunci);
        const res = punya
          ? await api(`/jadwal-rutins/${punya.id}`, { method: "PUT", body: JSON.stringify(muatan) })
          : await api("/jadwal-rutins", { method: "POST", body: JSON.stringify(muatan) });

        kabar.push(`${s.nama}: ${res.message}`);
      }

      setPesan(kabar.join(" · "));
      muat();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Gagal menyimpan");
    } finally {
      setMenyimpan(false);
    }
  }

  const input = "w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none";

  if (loading) return <p className="text-sm text-gray-400">Memuat...</p>;

  return (
    <div className="space-y-4">
      <h2 className="text-xl font-bold text-gray-900">Jadwal Sholat Berjamaah</h2>

      <p className="rounded-lg bg-gray-50 p-3 text-xs text-gray-600">
        Jam di sini yang menentukan kapan absen dibuka dan ditutup. Lewat dari jam selesai
        ditambah toleransinya, wajah yang discan tidak lagi diterima. Kolom paling kanan
        cuma pembanding — hitungan astronomi untuk hari ini, bukan perintah.
      </p>

      {error && <p className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
      {pesan && <p className="rounded bg-emerald-50 p-2 text-sm text-emerald-700">{pesan}</p>}

      <div className="rounded-xl border border-gray-200 bg-white p-4">
        <label className="mb-1 block text-xs font-medium text-gray-600" htmlFor="js-target">
          Berlaku untuk
        </label>
        <select id="js-target" className={`${input} sm:w-80`} value={target}
          onChange={(e) => setTarget(e.target.value)}>
          <option value="">Pilih wilayah...</option>
          {opsi.map((o) => <option key={o.nilai} value={o.nilai}>{o.label}</option>)}
        </select>
      </div>

      <div className="overflow-x-auto rounded-xl border border-gray-200 bg-white">
        <table className="w-full text-sm">
          <thead className="bg-gray-50 text-left text-xs text-gray-500">
            <tr>
              <th className="p-3">Sholat</th>
              <th className="p-3">Mulai</th>
              <th className="p-3">Selesai</th>
              <th className="p-3">Toleransi</th>
              <th className="p-3">Hitungan hari ini</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {SHOLAT.map((s) => {
              const baris = isi[s.kunci] ?? BARIS_KOSONG;
              const selisih = baris.mulai && hitungan[s.kunci]
                ? Math.abs(menitJam(baris.mulai) - menitJam(hitungan[s.kunci]))
                : 0;

              return (
                <tr key={s.kunci}>
                  <td className="p-3 font-medium text-gray-900">{s.nama}</td>
                  <td className="p-2">
                    <input type="time" className={input} value={baris.mulai} aria-label={`Mulai ${s.nama}`}
                      onChange={(e) => setIsi({ ...isi, [s.kunci]: { ...baris, mulai: e.target.value } })} />
                  </td>
                  <td className="p-2">
                    <input type="time" className={input} value={baris.selesai} aria-label={`Selesai ${s.nama}`}
                      onChange={(e) => setIsi({ ...isi, [s.kunci]: { ...baris, selesai: e.target.value } })} />
                  </td>
                  <td className="p-2">
                    <input type="number" min={0} max={60} className={input} value={baris.toleransi}
                      aria-label={`Toleransi ${s.nama}`}
                      onChange={(e) => setIsi({
                        ...isi,
                        [s.kunci]: { ...baris, toleransi: e.target.value === "" ? "" : Number(e.target.value) },
                      })} />
                  </td>
                  <td className="whitespace-nowrap p-3 text-gray-500">
                    {hitungan[s.kunci] ?? "-"}
                    {selisih >= AMBANG_PERINGATAN && (
                      <span className="ml-2 text-amber-700">⚠ selisih {selisih} menit</span>
                    )}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      <div className="flex flex-wrap gap-3">
        <button onClick={simpan} disabled={menyimpan || !target}
          className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50">
          {menyimpan ? "Menyimpan..." : "Simpan"}
        </button>
        <button onClick={isiDariHitungan}
          className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
          Isi dari hitungan
        </button>
      </div>
    </div>
  );
}

function menitJam(jam: string): number {
  const [j, m] = jam.split(":").map(Number);

  return j * 60 + m;
}

function tambahMenit(jam: string, menit: number): string {
  if (!jam) return "";

  const total = menitJam(jam) + menit;

  return `${String(Math.floor(total / 60) % 24).padStart(2, "0")}:${String(total % 60).padStart(2, "0")}`;
}
