"use client";

/**
 * Suara kiosk. Menyetel `utter.lang` saja tidak cukup: itu cuma permintaan, dan kalau
 * suara bawaan perangkat berbahasa Inggris, kalimat Indonesia dibaca dengan lafal
 * Inggris. Banyak HP sebenarnya punya suara Indonesia, hanya bukan yang default —
 * jadi suaranya dipilih sendiri.
 */
let suaraTerpilih: SpeechSynthesisVoice | null = null;

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

export function ucapkan(kalimat: string) {
  if (!adaSpeech()) return;
  const utter = new SpeechSynthesisUtterance(kalimat);
  utter.lang = "id-ID";
  if (suaraTerpilih) utter.voice = suaraTerpilih;
  window.speechSynthesis.cancel(); // potong ucapan sebelumnya biar tidak menumpuk kalau scan beruntun
  window.speechSynthesis.speak(utter);
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
