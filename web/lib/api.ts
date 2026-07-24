const BASE = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api";

export interface ApiResponse<T> {
  success: boolean;
  message: string;
  data: T;
}

export class ApiError extends Error {
  constructor(message: string, public status: number) {
    super(message);
  }
}

// Kiosk absen-wajah jalan berjam-jam tanpa reload — 401 di tengah sesi tak boleh langsung
// membuang halaman ke /login (kamera mati mendadak), biarkan halaman itu sendiri yang menampilkan overlay.
function isKioskPath(pathname: string): boolean {
  return /^\/kegiatan\/\d+\/absen-wajah/.test(pathname);
}

export async function api<T = unknown>(
  path: string,
  opts: RequestInit = {}
): Promise<ApiResponse<T>> {
  const token =
    typeof window !== "undefined" ? localStorage.getItem("token") : null;

  const res = await fetch(`${BASE}${path}`, {
    ...opts,
    headers: {
      Accept: "application/json",
      ...(opts.body instanceof FormData
        ? {}
        : { "Content-Type": "application/json" }),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...opts.headers,
    },
  });

  if (res.status === 401 && typeof window !== "undefined" && path !== "/auth/login") {
    localStorage.removeItem("token");
    if (!isKioskPath(window.location.pathname)) {
      window.location.href = "/login";
    }
  }

  const json = (await res.json().catch(() => null)) as ApiResponse<T> | null;

  if (!res.ok || !json || json.success === false) {
    throw new ApiError(json?.message ?? `HTTP ${res.status}`, res.status);
  }

  return json;
}
