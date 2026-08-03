"use client";

import { useParams } from "next/navigation";
import AbsenWajahKiosk from "@/components/AbsenWajahKiosk";

/** Kiosk dikunci ke satu kegiatan — dipakai kalau panitia mau absen kegiatan tertentu saja. */
export default function AbsenWajahKegiatanPage() {
  const { id } = useParams<{ id: string }>();

  return <AbsenWajahKiosk kegiatanId={id} />;
}
