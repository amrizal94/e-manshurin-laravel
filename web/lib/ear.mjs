// Eye Aspect Ratio (EAR) — rasio jarak vertikal vs horizontal titik mata.
// Scale-invariant (rasio jarak), jadi tidak perlu ikut ukuran wajah di frame.
// Titik urut: [sudutLuar, atas1, atas2, sudutDalam, bawah2, bawah1] (skema 68-point).

export const EAR_OPEN = 0.28;
export const EAR_CLOSED = 0.2;

function distance(a, b) {
  return Math.hypot(a.x - b.x, a.y - b.y);
}

export function eyeAspectRatio(mata) {
  return (distance(mata[1], mata[5]) + distance(mata[2], mata[4])) / (2 * distance(mata[0], mata[3]));
}
