"use client";

import { useEffect, useState } from "react";
import Image from "next/image";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { api } from "@/lib/api";

interface StoredUser {
  name: string;
  roles?: { name: string }[];
}

const NAV = [
  { href: "/dashboard", label: "Dashboard" },
  { href: "/struktur", label: "Struktur", roles: ["super_admin", "admin"] },
  { href: "/jamaah", label: "Jamaah", roles: ["super_admin", "admin"] },
  { href: "/kegiatan", label: "Kegiatan" },
  { href: "/rekap", label: "Rekapitulasi" },
  { href: "/pengguna", label: "Pengguna", roles: ["super_admin", "admin"] },
  { href: "/pengaturan", label: "Pengaturan", roles: ["super_admin", "admin"] },
  { href: "/activity-log", label: "Log Aktivitas", roles: ["super_admin"] },
];

export default function AppLayout({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const pathname = usePathname();
  const [user, setUser] = useState<StoredUser | null>(null);
  const [navOpen, setNavOpen] = useState(false);

  useEffect(() => {
    const token = localStorage.getItem("token");
    if (!token) {
      router.replace("/login");
      return;
    }
    const stored = localStorage.getItem("user");
    if (stored) setUser(JSON.parse(stored));
  }, [router]);

  useEffect(() => setNavOpen(false), [pathname]);

  async function logout() {
    if (!confirm("Yakin ingin keluar?")) return;
    try {
      await api("/auth/logout", { method: "POST" });
    } catch {
      // token sudah tidak valid pun tetap lanjut bersihkan sesi lokal
    }
    localStorage.removeItem("token");
    localStorage.removeItem("user");
    router.replace("/login");
  }

  const roleNames = user?.roles?.map((r) => r.name) ?? [];
  const visibleNav = NAV.filter(
    (item) => !item.roles || item.roles.some((r) => roleNames.includes(r))
  );

  return (
    <div className="flex min-h-screen bg-gray-50">
      {navOpen && (
        <div
          className="fixed inset-0 z-20 bg-black/30 lg:hidden"
          onClick={() => setNavOpen(false)}
        />
      )}

      <aside
        className={`fixed inset-y-0 left-0 z-30 flex w-64 -translate-x-full flex-col border-r border-gray-200 bg-white transition-transform duration-200 lg:static lg:z-auto lg:w-56 lg:translate-x-0 ${
          navOpen ? "translate-x-0" : ""
        }`}
      >
        <div className="flex items-center gap-2 border-b border-gray-200 p-4">
          <Image src="/logo.png" alt="" width={32} height={32} />
          <div>
            <h1 className="text-lg font-bold text-emerald-700">E-Manshurin</h1>
            <p className="truncate text-xs text-gray-500">{user?.name}</p>
          </div>
        </div>
        <nav className="flex-1 space-y-1 p-2">
          {visibleNav.map((item) => (
            <Link
              key={item.href}
              href={item.href}
              className={`block rounded-lg px-3 py-2 text-sm font-medium ${
                pathname.startsWith(item.href)
                  ? "bg-emerald-50 text-emerald-700"
                  : "text-gray-700 hover:bg-gray-100"
              }`}
            >
              {item.label}
            </Link>
          ))}
        </nav>
        <button
          onClick={logout}
          className="m-2 rounded-lg px-3 py-2 text-left text-sm font-medium text-red-600 hover:bg-red-50"
        >
          Keluar
        </button>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <div className="flex items-center gap-3 border-b border-gray-200 bg-white p-4 lg:hidden">
          <button
            onClick={() => setNavOpen(true)}
            aria-label="Buka menu"
            className="rounded-lg border border-gray-300 p-2 text-gray-600 hover:bg-gray-50"
          >
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-5 w-5">
              <line x1="4" y1="6" x2="20" y2="6" />
              <line x1="4" y1="12" x2="20" y2="12" />
              <line x1="4" y1="18" x2="20" y2="18" />
            </svg>
          </button>
          <Image src="/logo.png" alt="" width={28} height={28} />
          <h1 className="text-base font-bold text-emerald-700">E-Manshurin</h1>
        </div>

        <main className="flex-1 overflow-x-auto p-4 sm:p-6">{children}</main>
      </div>
    </div>
  );
}
