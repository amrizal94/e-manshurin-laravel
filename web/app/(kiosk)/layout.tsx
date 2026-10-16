"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";

export default function KioskLayout({ children }: { children: React.ReactNode }) {
  const router = useRouter();

  useEffect(() => {
    if (!localStorage.getItem("token")) router.replace("/login");
  }, [router]);

  return <div className="ruang-kendali min-h-screen">{children}</div>;
}
