"use client";

/**
 * Suara kiosk. Dua lapis: berkas rekaman hasil klon kalau ada, kalau tidak
 * `speechSynthesis` bawaan perangkat.
 *
 * Berkasnya dinamai sha1 kalimatnya, bukan id jamaah. Jadi templat yang diedit
 * pengurus otomatis menunjuk berkas lain (dan jatuh ke lapis kedua sampai
 * berkasnya dibangun ulang), dan dua jamaah yang sapaan+namanya kebetulan sama
 * berbagi satu berkas.
 *
 * Lapis kedua tidak dihapus: jamaah baru belum punya rekaman, kalimat "belum ada
 * kegiatan" memang sengaja tidak direkam karena jamnya bebas kombinasi, dan
 * `crypto.subtle` cuma ada di konteks aman.
 */
const ASAL = (process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api").replace(/\/api$/, "");

let suaraTerpilih: SpeechSynthesisVoice | null = null;
let pemutar: HTMLAudioElement | null = null;
/** Kunci yang berkasnya sudah terbukti tidak ada — biar tidak ditembak ulang tiap scan. */
const tanpaBerkas = new Set<string>();

function adaSpeech(): boolean {
  return typeof window !== "undefined" && "speechSynthesis" in window;
}

function cariSuaraIndonesia(): SpeechSynthesisVoice | null {
  if (!adaSpeech()) return null;
  const semua = window.speechSynthesis.getVoices();
  const berawalan = (kode: string) =>
    semua.find((v) => v.lang.replace("_", "-").toLowerCase().startsWith(kode));

  // Melayu jadi cadangan: lafalnya jauh lebih dekat ke Indonesia daripada Inggris
  return berawalan("id") ?? berawalan("ms") ?? null;
}

/** Pilih ulang suaranya. Balikannya true kalau perangkat cuma punya suara asing. */
export function segarkanSuara(): boolean {
  if (!adaSpeech()) return false;
  suaraTerpilih = cariSuaraIndonesia();

  return window.speechSynthesis.getVoices().length > 0 && suaraTerpilih === null;
}

/**
 * Dipanggil dari tap petugas. Elemen audio harus pernah disentuh gestur user
 * sekali, kalau tidak Safari/iOS menolak play() berkas rekaman nanti — sama
 * seperti speechSynthesis.
 */
export function siapkanAudio() {
  if (typeof window === "undefined" || pemutar) return;

  pemutar = new Audio();
  pemutar.preload = "auto";
  pemutar.play().catch(() => {
    // src masih kosong, jadi ini memang gagal. Yang dibutuhkan cuma gesturnya tercatat.
  });
}

async function kunci(kalimat: string): Promise<string | null> {
  if (typeof crypto === "undefined" || !crypto.subtle) return null;

  const cerna = await crypto.subtle.digest("SHA-1", new TextEncoder().encode(kalimat));

  return Array.from(new Uint8Array(cerna))
    .map((b) => b.toString(16).padStart(2, "0"))
    .join("")
    .slice(0, 16);
}

function putarBerkas(url: string): Promise<void> {
  return new Promise((selesai, tolak) => {
    const el = pemutar ?? new Audio();
    pemutar = el;

    const bersih = () => {
      el.removeEventListener("ended", beres);
      el.removeEventListener("error", galat);
    };
    const beres = () => { bersih(); selesai(); };
    const galat = () => { bersih(); tolak(new Error("berkas suara tidak terputar")); };

    el.addEventListener("ended", beres);
    el.addEventListener("error", galat);
    el.src = url;
    el.play().catch(galat);
  });
}

function ucapkanSintetis(kalimat: string) {
  if (!adaSpeech()) return;
  const utter = new SpeechSynthesisUtterance(kalimat);
  utter.lang = "id-ID";
  if (suaraTerpilih) utter.voice = suaraTerpilih;
  window.speechSynthesis.speak(utter);
}

export async function ucapkan(kalimat: string) {
  // Potong ucapan sebelumnya biar tidak menumpuk kalau scan beruntun.
  if (adaSpeech()) window.speechSynthesis.cancel();
  if (pemutar && !pemutar.paused) pemutar.pause();

  const k = await kunci(kalimat);
  if (k && !tanpaBerkas.has(k)) {
    try {
      await putarBerkas(`${ASAL}/storage/suara/${k}.mp3`);

      return;
    } catch {
      tanpaBerkas.add(k);
    }
  }

  ucapkanSintetis(kalimat);
}

/** Placeholder yang tidak dikenal dibiarkan apa adanya — biar salah ketik kedengaran, bukan hilang diam-diam. */
export function isiTemplate(template: string, nilai: Record<string, string>): string {
  return template.replace(/\{(\w+)\}/g, (utuh, kunci: string) => nilai[kunci] ?? utuh);
}

/**
 * Buang kalimat yang memuat placeholder ini. Dipakai saat hari itu sudah tidak ada
 * jadwal lagi: kalau {jam_berikutnya} cuma dikosongkan, kiosk membaca
 * "Pengajian berikutnya pukul." — kalimatnya lebih baik tidak diucapkan sama sekali.
 */
export function buangKalimat(template: string, placeholder: string): string {
  return (template.match(/[^.]+\.?/g) ?? [template])
    .filter((kalimat) => !kalimat.includes(placeholder))
    .join("")
    .trim();
}
