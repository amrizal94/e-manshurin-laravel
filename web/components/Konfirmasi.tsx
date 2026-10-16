"use client";

import { createContext, useCallback, useContext, useEffect, useRef, useState } from "react";

export interface OpsiKonfirmasi {
  pesan: string;
  /** Akibatnya — apa yang ikut hilang, apa yang masih bisa dibatalkan. */
  keterangan?: string;
  tombol?: string;
  /** Merah, untuk yang menghapus. Warna beda memberi jeda sepersekian detik sebelum jari menekan. */
  bahaya?: boolean;
}

type Tanya = (opsi: OpsiKonfirmasi | string) => Promise<boolean>;

const Konteks = createContext<Tanya>(async () => false);

/** `const tanya = useKonfirmasi()` lalu `if (!(await tanya("..."))) return;` */
export function useKonfirmasi(): Tanya {
  return useContext(Konteks);
}

/**
 * Pengganti confirm() bawaan browser.
 *
 * Yang bawaan tidak bisa diwarnai dan selalu menyebut nama domain lebih dulu sebelum
 * pertanyaannya — di aplikasi yang sudah dirapikan, itu yang paling kelihatan murah.
 *
 * Memakai <dialog> bawaan HTML, bukan pustaka: lapisan atas, latar gelap, tombol Esc,
 * dan fokus yang terkunci di dalam dialog sudah ditangani browser.
 */
export function PenyediaKonfirmasi({ children }: { children: React.ReactNode }) {
  const dialogRef = useRef<HTMLDialogElement>(null);
  const [opsi, setOpsi] = useState<OpsiKonfirmasi | null>(null);
  const jawab = useRef<(ya: boolean) => void>(() => {});

  const tanya = useCallback<Tanya>((o) => {
    setOpsi(typeof o === "string" ? { pesan: o } : o);

    return new Promise<boolean>((selesai) => {
      jawab.current = selesai;
    });
  }, []);

  // Dibuka sesudah isinya terpasang, supaya tidak sempat berkedip kosong.
  useEffect(() => {
    if (opsi) dialogRef.current?.showModal();
  }, [opsi]);

  function tutup(ya: boolean) {
    dialogRef.current?.close();
    setOpsi(null);
    jawab.current(ya);
  }

  const bahaya = opsi?.bahaya ?? false;

  return (
    <Konteks.Provider value={tanya}>
      {children}

      <dialog
        ref={dialogRef}
        onCancel={(e) => {
          // Esc: cegah penutupan bawaan supaya janjinya tetap dijawab lewat satu pintu.
          e.preventDefault();
          tutup(false);
        }}
        // m-auto dipasang sendiri: Tailwind menghapus margin bawaan semua elemen, termasuk
        // margin:auto milik <dialog> yang biasanya menengahkannya di layar.
        className="m-auto max-w-[calc(100vw-2rem)] bg-transparent p-0 backdrop:bg-black/70 backdrop:backdrop-blur-sm"
      >
        {opsi && (
          <div
            className="ruang-kendali rounded"
            // Siku dan garisnya memakai --sian; cukup ditukar untuk membuat versi merahnya.
            style={bahaya ? ({ "--sian": "#ff4d5e" } as React.CSSProperties) : undefined}
          >
            <div className="panel w-[22rem] max-w-full p-5">
              <p className="judul-hud mb-3 flex items-center gap-2 text-xs text-[color:var(--sian)]">
                <span className="h-[7px] w-[7px] rotate-45 bg-[color:var(--sian)] shadow-[0_0_8px_var(--sian)]" />
                {bahaya ? "Konfirmasi Hapus" : "Konfirmasi"}
              </p>

              <p className="text-sm text-white">{opsi.pesan}</p>
              {opsi.keterangan && (
                <p className="mt-2 text-xs leading-relaxed text-[color:var(--redup)]">{opsi.keterangan}</p>
              )}

              <div className="mt-5 flex justify-end gap-2">
                <button
                  // Yang menghapus tidak boleh kena Enter refleks: fokus awalnya di Batal.
                  autoFocus={bahaya}
                  onClick={() => tutup(false)}
                  className="rounded border border-[color:var(--garis)] px-4 py-2 text-xs text-[color:var(--teks)] hover:bg-white/5"
                >
                  Batal
                </button>
                <button
                  autoFocus={!bahaya}
                  onClick={() => tutup(true)}
                  className="judul-hud rounded border border-[color:var(--sian)] bg-[color:var(--sian)]/15 px-4 py-2 text-xs text-white shadow-[0_0_18px_rgba(0,212,255,.25)] hover:bg-[color:var(--sian)]/25"
                >
                  {opsi.tombol ?? (bahaya ? "Hapus" : "Lanjut")}
                </button>
              </div>
            </div>
          </div>
        )}
      </dialog>
    </Konteks.Provider>
  );
}
