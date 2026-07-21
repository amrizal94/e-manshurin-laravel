"use client";

import { useState } from "react";
import Image from "next/image";
import { useRouter } from "next/navigation";
import { api } from "@/lib/api";

const FITUR = [
  "Absensi wajah real-time",
  "Struktur daerah, desa & kelompok",
  "Rekapitulasi kehadiran otomatis",
  "Izin via WhatsApp",
];

export default function LoginPage() {
  const router = useRouter();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

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
      router.push("/dashboard");
    } catch (err) {
      setError(err instanceof Error ? err.message : "Login gagal");
    } finally {
      setLoading(false);
    }
  }

  return (
    <main className="flex min-h-screen">
      <section className="relative hidden w-1/2 flex-col justify-center overflow-hidden bg-emerald-900 px-16 text-white lg:flex">
        <Image src="/logo.png" alt="E-Manshurin" width={80} height={80} />
        <h1 className="mt-6 text-3xl font-bold">E-Manshurin</h1>
        <p className="mt-2 text-emerald-200">
          Absensi Pengajian Berbasis Wajah
        </p>
        <ul className="mt-8 space-y-3 text-sm text-emerald-100">
          {FITUR.map((f) => (
            <li key={f} className="flex items-center gap-2">
              <span className="h-1.5 w-1.5 rounded-full bg-emerald-300" />
              {f}
            </li>
          ))}
        </ul>
      </section>

      <section className="flex flex-1 items-center justify-center bg-gray-50 p-4">
        <form
          onSubmit={submit}
          className="w-full max-w-sm space-y-4 rounded-xl bg-white p-8 shadow"
        >
          <div className="text-center lg:hidden">
            <Image
              src="/logo.png"
              alt="E-Manshurin"
              width={64}
              height={64}
              className="mx-auto"
            />
          </div>
          <div>
            <h2 className="text-2xl font-bold text-gray-900">Masuk</h2>
            <p className="text-sm text-gray-500">
              Masukkan email dan password Anda
            </p>
          </div>
          {error && (
            <p className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>
          )}
          <div>
            <label className="mb-1 block text-sm font-medium text-gray-700">
              Email
            </label>
            <input
              type="email"
              required
              autoComplete="username"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 focus:border-emerald-500 focus:outline-none"
            />
          </div>
          <div>
            <label className="mb-1 block text-sm font-medium text-gray-700">
              Password
            </label>
            <input
              type="password"
              required
              autoComplete="current-password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 focus:border-emerald-500 focus:outline-none"
            />
          </div>
          <button
            type="submit"
            disabled={loading}
            className="w-full rounded-lg bg-emerald-600 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
          >
            {loading ? "Masuk..." : "Masuk"}
          </button>
        </form>
      </section>
    </main>
  );
}
