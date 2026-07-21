const BASE = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api";

export interface ApiResponse<T> {
  success: boolean;
  message: string;
  data: T;
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

  if (res.status === 401 && typeof window !== "undefined") {
    localStorage.removeItem("token");
    window.location.href = "/login";
  }

  const json = (await res.json().catch(() => null)) as ApiResponse<T> | null;

  if (!res.ok || !json || json.success === false) {
    throw new Error(json?.message ?? `HTTP ${res.status}`);
  }

  return json;
}
