"use client";

import { Suspense, useEffect, useState } from "react";
import Image from "next/image";
import { useRouter, useSearchParams } from "next/navigation";
import { api } from "@/lib/api";

const FITUR = [
  "Absensi wajah real-time",
  "Struktur daerah, desa & kelompok",
  "Rekapitulasi kehadiran otomatis",
  "Izin via WhatsApp",
];

export default function LoginPage() {
  return (
    <Suspense>
      <LoginForm />
    </Suspense>
  );
}

function LoginForm() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const tujuan = searchParams.get("redirect") || "/dashboard";
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const [lihatPassword, setLihatPassword] = useState(false);

  useEffect(() => {
    if (localStorage.getItem("token")) router.replace(tujuan);
  }, [router, tujuan]);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setError("");
    setLoading(true);
    try {
      const res = await api<{ token: string; user: unknown }>("/auth/login", {
        method: "POST",
        body: JSON.stringify({ email, password }),
      });
      localStorage.setItem("token", res.data.token);
      localStorage.setItem("user", JSON.stringify(res.data.user));
      router.push(tujuan);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Login gagal");
    } finally {
      setLoading(false);
    }
  }

  return (
    <main className="ruang-kendali flex min-h-screen">
      <section className="relative hidden w-1/2 flex-col justify-center overflow-hidden px-16 lg:flex">
        <Image src="/logo.png" alt="E-Manshurin" width={80} height={80} className="rounded-full bg-white p-0.5 ring-1 ring-[color:var(--garis)]" />
        <h1 className="judul-hud mt-6 text-3xl text-white">E-Manshurin</h1>
        <p className="mt-2 text-[color:var(--sian)]">
          Absensi Pengajian Berbasis Wajah
        </p>
        <ul className="mt-8 space-y-3 text-sm text-[color:var(--teks)]">
          {FITUR.map((f) => (
            <li key={f} className="flex items-center gap-2">
              <span className="h-1.5 w-1.5 rotate-45 bg-[color:var(--sian)] shadow-[0_0_8px_var(--sian)]" />
              {f}
            </li>
          ))}
        </ul>
      </section>

      <section className="flex flex-1 items-center justify-center p-4">
        <form
          onSubmit={submit}
          className="panel w-full max-w-sm space-y-4 p-8"
        >
          <div className="text-center lg:hidden">
            <Image
              src="/logo.png"
              alt="E-Manshurin"
              width={64}
              height={64}
              className="mx-auto rounded-full bg-white p-0.5 ring-1 ring-[color:var(--garis)]"
            />
          </div>
          <div>
            <h2 className="judul-hud text-2xl text-white">Masuk</h2>
            <p className="text-sm text-[color:var(--redup)]">
              Masukkan email dan password Anda
            </p>
          </div>
          {error && (
            <p className="rounded border border-red-500/40 bg-red-500/10 p-2 text-sm text-red-300">{error}</p>
          )}
          <div>
            <label htmlFor="lg-email" className="mb-1 block text-xs uppercase tracking-widest text-[color:var(--redup)]">
              Email
            </label>
            <input
              id="lg-email"
              type="email"
              required
              autoComplete="username"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              className="w-full rounded border border-[color:var(--garis)] bg-black/30 px-3 py-2 text-sm text-white focus:border-[color:var(--sian)] focus:outline-none"
            />
          </div>
          <div>
            <label htmlFor="lg-password" className="mb-1 block text-xs uppercase tracking-widest text-[color:var(--redup)]">
              Password
            </label>
            <div className="relative">
              <input
                id="lg-password"
                type={lihatPassword ? "text" : "password"}
                required
                autoComplete="current-password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                className="w-full rounded border border-[color:var(--garis)] bg-black/30 px-3 py-2 pr-10 text-sm text-white focus:border-[color:var(--sian)] focus:outline-none"
              />
              <button
                type="button"
                onClick={() => setLihatPassword((v) => !v)}
                className="absolute inset-y-0 right-0 flex items-center px-3 text-[color:var(--redup)] hover:text-white"
                aria-label={lihatPassword ? "Sembunyikan password" : "Tampilkan password"}
              >
                {lihatPassword ? (
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-4 w-4">
                    <path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a20.3 20.3 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a20.28 20.28 0 0 1-3.22 4.44M14.12 14.12a3 3 0 1 1-4.24-4.24" />
                    <line x1="1" y1="1" x2="23" y2="23" />
                  </svg>
                ) : (
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-4 w-4">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z" />
                    <circle cx="12" cy="12" r="3" />
                  </svg>
                )}
              </button>
            </div>
          </div>
          <button
            type="submit"
            disabled={loading}
            className="judul-hud w-full rounded border border-[color:var(--sian)] bg-[color:var(--sian)]/15 py-2 text-sm text-white shadow-[0_0_18px_rgba(0,212,255,.25)] hover:bg-[color:var(--sian)]/25 disabled:opacity-50"
          >
            {loading ? "Masuk..." : "Masuk"}
          </button>
        </form>
      </section>
    </main>
  );
}
