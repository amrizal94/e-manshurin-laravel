"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { buangKalimat, isiTemplate, segarkanSuara, ucapkan } from "@/lib/suara";
import { useRoleGuard } from "@/lib/useRoleGuard";

const JAM_BERIKUTNYA = "{jam_berikutnya}";

/** Nilai contoh untuk tombol coba dengar — dibaca, bukan disimpan. */
const CONTOH = {
  doa: "Alhamdulillah, Jazakallahu Khoiro",
  sapaan: "Mas",
  nama: "Fauzan",
  jam_berikutnya: "19.30",
};

const KARTU = [
  {
    key: "wa_reply_template",
    judul: "Balasan WA Izin",
    placeholder: ["{nama}", "{keterangan}", "{kegiatan}"],
    suara: false,
    catatan: "",
  },
  {
    key: "suara_absen",
    judul: "Suara Setelah Absen",
    placeholder: ["{doa}", "{sapaan}", "{nama}"],
    suara: true,
    catatan:
      "{doa} berisi Jazakallahu/Jazakillahu Khoiro sesuai jenis kelamin, {sapaan} berisi Dek, Mas, Mbak, Pak, atau Bu sesuai umur.",
  },
  {
    key: "suara_idle",
    judul: "Suara di Luar Jam Kegiatan",
    placeholder: ["{sapaan}", "{nama}", JAM_BERIKUTNYA],
    suara: true,
    catatan:
      "Kalimat yang memuat {jam_berikutnya} tidak dibacakan kalau hari itu sudah tidak ada jadwal lagi.",
  },
] as const;

export default function PengaturanPage() {
  useRoleGuard(["super_admin", "admin"]);
  const [isi, setIsi] = useState<Record<string, string>>({});
  const [pesan, setPesan] = useState("");
  const [error, setError] = useState("");
  const [menyimpan, setMenyimpan] = useState("");

  useEffect(() => {
    api<Record<string, string>>("/settings")
      .then((res) => setIsi(res.data))
      .catch((err) => setError(err.message));
  }, []);

  // Daftar suara sering masih kosong saat halaman baru dimuat, jadi ditunggu lewat
  // voiceschanged — kalau tidak, coba dengar yang pertama memakai suara asing.
  useEffect(() => {
    if (typeof window === "undefined" || !("speechSynthesis" in window)) return;
    segarkanSuara();
    window.speechSynthesis.addEventListener("voiceschanged", segarkanSuara);
    return () => window.speechSynthesis.removeEventListener("voiceschanged", segarkanSuara);
  }, []);

  async function simpan(key: string) {
    setMenyimpan(key);
    setError("");
    setPesan("");
    try {
      await api(`/settings/${key}`, { method: "PUT", body: JSON.stringify({ value: isi[key] ?? "" }) });
      setPesan("Pengaturan disimpan");
    } catch (err) {
      setError(err instanceof Error ? err.message : "Gagal menyimpan");
    } finally {
      setMenyimpan("");
    }
  }

  return (
    <div className="max-w-xl space-y-4">
      <h2 className="text-xl font-bold text-gray-900">Pengaturan</h2>

      {error && <p className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
      {pesan && <p className="rounded bg-emerald-50 p-2 text-sm text-emerald-700">{pesan}</p>}

      {KARTU.map((kartu) => (
        <div key={kartu.key} className="space-y-3 rounded-xl border border-gray-200 bg-white p-4">
          <h3 className="text-sm font-semibold text-gray-900">{kartu.judul}</h3>

          <p className="text-xs text-gray-500">
            Placeholder tersedia:{" "}
            {kartu.placeholder.map((p, i) => (
              <span key={p}>
                {i > 0 && ", "}
                <code>{p}</code>
              </span>
            ))}
          </p>
          {kartu.catatan && <p className="text-xs text-gray-500">{kartu.catatan}</p>}

          <textarea
            rows={3}
            aria-label={kartu.judul}
            value={isi[kartu.key] ?? ""}
            onChange={(e) => setIsi((s) => ({ ...s, [kartu.key]: e.target.value }))}
            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
          />

          <div className="flex flex-wrap gap-2">
            <button
              onClick={() => simpan(kartu.key)}
              disabled={menyimpan === kartu.key}
              className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
            >
              {menyimpan === kartu.key ? "Menyimpan..." : "Simpan"}
            </button>

            {/* Teks ini tidak pernah dibaca mata, cuma didengar: koma yang kurang atau
                istilah yang dilafalkan aneh hanya ketahuan dengan mendengarkannya. */}
            {kartu.suara && (
              <button
                onClick={() => ucapkan(isiTemplate(isi[kartu.key] ?? "", CONTOH))}
                className="rounded-lg border border-emerald-600 px-4 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-50"
              >
                🔊 Coba Dengar
              </button>
            )}

            {kartu.key === "suara_idle" && (
              <button
                onClick={() =>
                  ucapkan(isiTemplate(buangKalimat(isi[kartu.key] ?? "", JAM_BERIKUTNYA), CONTOH))
                }
                className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50"
              >
                Coba tanpa jadwal berikutnya
              </button>
            )}
          </div>
        </div>
      ))}
    </div>
  );
}
