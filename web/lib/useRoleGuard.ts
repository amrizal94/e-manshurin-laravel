"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";

interface StoredUser {
  roles?: { name: string }[];
}

/** Defense-in-depth: redirect ke dashboard kalau role gak diizinkan. Backend tetap sumber otoritas asli. */
export function useRoleGuard(allowed: string[]) {
  const router = useRouter();

  useEffect(() => {
    const stored = localStorage.getItem("user");
    if (!stored) return;
    const roles = ((JSON.parse(stored) as StoredUser).roles ?? []).map((r) => r.name);
    if (roles.length && !allowed.some((r) => roles.includes(r))) {
      router.replace("/dashboard");
    }
  }, [router, allowed]);
}
