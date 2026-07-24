"use client";

interface PaginationProps {
  page: number;
  lastPage: number;
  onChange: (page: number) => void;
}

export function Pagination({ page, lastPage, onChange }: PaginationProps) {
  if (lastPage <= 1) return null;

  return (
    <div className="flex items-center gap-3">
      <button
        onClick={() => onChange(Math.max(1, page - 1))}
        disabled={page <= 1}
        className="rounded border border-gray-300 px-3 py-1 disabled:opacity-40"
      >
        ← Sebelumnya
      </button>
      <span>Halaman {page} / {lastPage}</span>
      <button
        onClick={() => onChange(Math.min(lastPage, page + 1))}
        disabled={page >= lastPage}
        className="rounded border border-gray-300 px-3 py-1 disabled:opacity-40"
      >
        Selanjutnya →
      </button>
    </div>
  );
}
