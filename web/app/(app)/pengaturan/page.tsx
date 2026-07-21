"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";

export default function PengaturanPage() {
  const [template, setTemplate] = useState("");
  const [pesan, setPesan] = useState("");
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    api<{ template: string }>("/settings/wa-reply-template")
      .then((res) => setTemplate(res.data.template))
      .catch((err) => setError(err.message));
  }, []);

  async function simpan(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError("");
    setPesan("");
    try {
      await api("/settings/wa-reply-template", {
        method: "PUT",
        body: JSON.stringify({ template }),
      });
      setPesan("Template balasan disimpan");
    } catch (err) {
      setError(err instanceof Error ? err.message : "Gagal menyimpan");
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="max-w-xl space-y-4">
      <h2 className="text-xl font-bold text-gray-900">Pengaturan</h2>

      <form onSubmit={simpan} className="space-y-3 rounded-xl border border-gray-200 bg-white p-4">
        <h3 className="text-sm font-semibold text-gray-900">Balasan WA Izin</h3>
        <p className="text-xs text-gray-500">
          Placeholder tersedia: <code>{"{nama}"}</code>, <code>{"{keterangan}"}</code>, <code>{"{kegiatan}"}</code>
        </p>
        {error && <p className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        {pesan && <p className="rounded bg-emerald-50 p-2 text-sm text-emerald-700">{pesan}</p>}
        <textarea
          rows={3}
          value={template}
          onChange={(e) => setTemplate(e.target.value)}
          className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
        />
        <button
          type="submit"
          disabled={saving}
          className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
        >
          {saving ? "Menyimpan..." : "Simpan"}
        </button>
      </form>
    </div>
  );
}
