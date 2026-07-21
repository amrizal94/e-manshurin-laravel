// Self-check: node lib/ear.check.mjs
import assert from "node:assert/strict";
import { eyeAspectRatio, EAR_OPEN, EAR_CLOSED } from "./ear.mjs";

const mataTerbuka = [
  { x: 0, y: 0 }, { x: 2, y: -3 }, { x: 4, y: -3 },
  { x: 6, y: 0 }, { x: 4, y: 3 }, { x: 2, y: 3 },
];
const mataTertutup = [
  { x: 0, y: 0 }, { x: 2, y: -0.3 }, { x: 4, y: -0.3 },
  { x: 6, y: 0 }, { x: 4, y: 0.3 }, { x: 2, y: 0.3 },
];

assert.ok(EAR_CLOSED < EAR_OPEN, "threshold tertutup harus lebih kecil dari terbuka");
assert.ok(eyeAspectRatio(mataTerbuka) > EAR_OPEN, "mata terbuka harus di atas ambang buka");
assert.ok(eyeAspectRatio(mataTertutup) < EAR_CLOSED, "mata tertutup harus di bawah ambang tutup");

console.log("ear.check.mjs: OK");
