// Eye Aspect Ratio (EAR) — rasio jarak vertikal vs horizontal titik mata.
// Scale-invariant (rasio jarak), jadi tidak perlu ikut ukuran wajah di frame.
// Titik urut: [sudutLuar, atas1, atas2, sudutDalam, bawah2, bawah1] (skema 68-point).

// ponytail: nilai absolut ini bervariasi per bentuk mata/wajah/sudut kamera — dikalibrasi ulang
// dari data nyata (device iOS, EAR mata terbuka terbaca ~0.226 dengan tinyFaceDetector+tiny landmark).
// Kalau masih gak akurat buat wajah lain, naikkan/turunkan sesuai angka EAR live di layar absen-wajah.
export const EAR_OPEN = 0.23;
export const EAR_CLOSED = 0.16;

function distance(a, b) {
  return Math.hypot(a.x - b.x, a.y - b.y);
}

export function eyeAspectRatio(mata) {
  return (distance(mata[1], mata[5]) + distance(mata[2], mata[4])) / (2 * distance(mata[0], mata[3]));
}
