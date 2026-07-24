"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { Pagination } from "@/components/Pagination";

interface Log {
  id: number;
  description: string;
  event: string;
  subject_type: string | null;
  subject_id: number | null;
  causer: { id: number; name: string } | null;
  properties: { attributes?: Record<string, unknown>; old?: Record<string, unknown> };
  created_at: string;
}

const EVENT_LABEL: Record<string, string> = {
  created: "Dibuat",
  updated: "Diubah",
  deleted: "Dihapus",
};

function subjectLabel(type: string | null): string {
  if (!type) return "-";
  return type.replace("App\\Models\\", "");
}

export default function ActivityLogPage() {
  const [logs, setLogs] = useState<Log[]>([]);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    // defer 1 microtask: react-hooks/set-state-in-effect gak suka setState sinkron di body effect
    Promise.resolve().then(() => { setLoading(true); setError(""); });
    api<{ data: Log[]; last_page: number }>(`/activity-logs?page=${page}`)
      .then((res) => {
        setLogs(res.data.data);
        setLastPage(res.data.last_page);
      })
      .catch((err) => setError(err instanceof Error ? err.message : "Gagal memuat"))
      .finally(() => setLoading(false));
  }, [page]);

  return (
    <div className="space-y-4">
      <h2 className="text-xl font-bold text-gray-900">Log Aktivitas</h2>
      <p className="text-sm text-gray-500">Riwayat perubahan data untuk keperluan audit.</p>

      {error && <p className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}

      <div className="space-y-2 sm:hidden">
        {!loading && logs.length === 0 && (
          <p className="rounded-xl border border-gray-200 bg-white p-6 text-center text-sm text-gray-400">Belum ada aktivitas tercatat</p>
        )}
        {logs.map((log) => (
          <div key={log.id} className="rounded-xl border border-gray-200 bg-white p-3">
            <div className="flex items-center justify-between gap-2">
              <span className="rounded bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">
                {EVENT_LABEL[log.event] ?? log.description}
              </span>
              <span className="whitespace-nowrap text-xs text-gray-500">
                {new Date(log.created_at).toLocaleString("id-ID")}
              </span>
            </div>
            <p className="mt-1 text-sm text-gray-900">{log.causer?.name ?? "Sistem"}</p>
            <p className="text-xs text-gray-700">
              {subjectLabel(log.subject_type)}
              {log.subject_id ? ` #${log.subject_id}` : ""}
            </p>
            <p className="truncate text-xs text-gray-400" title={JSON.stringify(log.properties)}>
              {log.event === "updated" && log.properties.attributes
                ? Object.keys(log.properties.attributes).join(", ")
                : "-"}
            </p>
          </div>
        ))}
      </div>

      <div className="hidden overflow-x-auto rounded-xl border border-gray-200 bg-white sm:block">
        <table className="w-full text-sm">
          <thead className="bg-gray-50 text-left text-xs text-gray-500">
            <tr>
              <th className="p-3">Waktu</th>
              <th className="p-3">Pengguna</th>
              <th className="p-3">Aksi</th>
              <th className="p-3">Data</th>
              <th className="p-3">Detail</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {logs.map((log) => (
              <tr key={log.id}>
                <td className="whitespace-nowrap p-3 text-gray-500">
                  {new Date(log.created_at).toLocaleString("id-ID")}
                </td>
                <td className="p-3 text-gray-900">{log.causer?.name ?? "Sistem"}</td>
                <td className="p-3">
                  <span className="rounded bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">
                    {EVENT_LABEL[log.event] ?? log.description}
                  </span>
                </td>
                <td className="p-3 text-gray-700">
                  {subjectLabel(log.subject_type)}
                  {log.subject_id ? ` #${log.subject_id}` : ""}
                </td>
                <td className="max-w-xs truncate p-3 text-xs text-gray-400" title={JSON.stringify(log.properties)}>
                  {log.event === "updated" && log.properties.attributes
                    ? Object.keys(log.properties.attributes).join(", ")
                    : "-"}
                </td>
              </tr>
            ))}
            {!loading && logs.length === 0 && (
              <tr>
                <td colSpan={5} className="p-6 text-center text-sm text-gray-400">
                  Belum ada aktivitas tercatat
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      <div className="flex justify-center text-sm text-gray-500">
        <Pagination page={page} lastPage={lastPage} onChange={setPage} />
      </div>
    </div>
  );
}
