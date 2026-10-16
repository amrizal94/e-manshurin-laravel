"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";

/**
 * Kembali ke daftar asal, lengkap dengan saringan dan nomor halamannya.
 *
 * Tautan biasa ke "/jamaah" selalu membuka daftar polos, jadi saringan yang baru saja
 * disusun petugas hilang begitu dia selesai mengurus satu orang — dan foto wajah ditata
 * satu per satu, jadi itu terjadi puluhan kali. Riwayat browser sudah menyimpan keadaan
 * halaman sebelumnya; cukup dipakai.
 *
 * Kalau halaman ini dibuka langsung dari tautan — tidak ada riwayat untuk dimundurkan —
 * jatuh ke alamat biasa seperti dulu.
 */
export function TombolKembali({ href, children }: { href: string; children: React.ReactNode }) {
  const router = useRouter();
  const kelas = "text-sm text-gray-500 hover:text-gray-700";

  function kembali(e: React.MouseEvent) {
    if (typeof window === "undefined" || window.history.length <= 1) return;

    e.preventDefault();
    router.back();
  }

  return (
    <Link href={href} onClick={kembali} className={kelas}>
      {children}
    </Link>
  );
}
