"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";

export default function KioskLayout({ children }: { children: React.ReactNode }) {
  const router = useRouter();

  useEffect(() => {
    if (!localStorage.getItem("token")) router.replace("/login");
  }, [router]);

  return <div className="min-h-screen bg-gray-950">{children}</div>;
}
