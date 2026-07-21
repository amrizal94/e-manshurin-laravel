"use client";

import { useEffect, useState } from "react";
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
  { href: "/pengaturan", label: "Pengaturan", roles: ["super_admin", "admin"] },
];

export default function AppLayout({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const pathname = usePathname();
  const [user, setUser] = useState<StoredUser | null>(null);

  useEffect(() => {
    const token = localStorage.getItem("token");
    if (!token) {
      router.replace("/login");
      return;
    }
    const stored = localStorage.getItem("user");
    if (stored) setUser(JSON.parse(stored));
  }, [router]);

  async function logout() {
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
      <aside className="flex w-56 flex-col border-r border-gray-200 bg-white">
        <div className="border-b border-gray-200 p-4">
          <h1 className="text-lg font-bold text-emerald-700">E-Manshurin</h1>
          <p className="truncate text-xs text-gray-500">{user?.name}</p>
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
      <main className="flex-1 overflow-x-auto p-6">{children}</main>
    </div>
  );
}
