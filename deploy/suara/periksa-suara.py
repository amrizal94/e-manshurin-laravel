#!/usr/bin/env python3
"""Periksa berkas suara kiosk dengan membalik-transkripnya.

Piper deterministik, tapi Seed-VC tidak: difusinya mengacak, dan pada 311
kalimat ada 25 yang rusak parah di konversinya -- "Mas TUKIMIN" 1,00 di Piper
jatuh ke 0,33 sesudah dikonversi. Diulang, 4 dari 6 yang dicoba membaik telak
(TUKIMIN jadi 0,93), 2 mendarat sama jeleknya.

Karena itu --hapus-buruk ada: berkas di bawah ambang dihapus supaya putaran
bangun berikutnya membuatnya lagi dengan undian baru. Yang tetap jelek sesudah
beberapa putaran kemungkinan ejaannya, bukan nasibnya -- itu ranah ejaan.json.

Transkrip klip dua detik ejaannya sering ngawur ("Sakha" -> "saha") padahal
bunyinya benar, jadi yang dipakai kemiripan, bukan kecocokan persis.

Pemakaian: python3 periksa-suara.py keluaran/ [--ambang 0.8] [--hapus-buruk]
"""
import difflib
import json
import re
import subprocess
import sys
from pathlib import Path

WHISPER = "/opt/homebrew/bin/whisper-cli"
MODEL = Path.home() / "whisper/model/ggml-large-v3-turbo.bin"


def huruf(s):
    return re.sub(r"[^a-z0-9]", "", s.lower())


def main():
    tujuan = Path(sys.argv[1])
    ambang = float(sys.argv[sys.argv.index("--ambang") + 1]) if "--ambang" in sys.argv else 0.8

    peta = json.loads((tujuan / "peta.json").read_text(encoding="utf-8"))["berkas"]
    wav = sorted(tujuan.glob("*.wav"))

    # Satu panggilan whisper untuk semua: model 1,6 GB dimuat sekali, bukan 311 kali.
    baru = [b for b in wav if not Path(str(b) + ".txt").exists()]
    if baru:
        subprocess.run([WHISPER, "-m", str(MODEL), "-l", "id", "-nt", "-otxt", *map(str, baru)],
                       check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

    laporan = []
    for b in wav:
        diminta = peta.get(b.stem, {}).get("teks")
        if not diminta:
            continue
        dengar = Path(str(b) + ".txt").read_text(encoding="utf-8", errors="replace").strip()
        laporan.append({
            "kunci": b.stem, "teks": diminta, "terdengar": dengar,
            "nilai": round(difflib.SequenceMatcher(None, huruf(diminta), huruf(dengar)).ratio(), 3),
        })

    laporan.sort(key=lambda r: r["nilai"])
    (tujuan / "laporan.json").write_text(json.dumps(laporan, ensure_ascii=False, indent=1), encoding="utf-8")

    buruk = [r for r in laporan if r["nilai"] < ambang]
    rata = sum(r["nilai"] for r in laporan) / len(laporan) if laporan else 0
    print(f"{len(laporan)} berkas diperiksa, rata-rata {rata:.3f}, {len(buruk)} di bawah {ambang}", flush=True)
    for r in buruk:
        print(f"  {r['nilai']:.2f} {r['teks']!r} -> {r['terdengar']!r}", flush=True)

    if "--hapus-buruk" in sys.argv:
        for r in buruk:
            (tujuan / f"{r['kunci']}.wav").unlink(missing_ok=True)
            Path(str(tujuan / f"{r['kunci']}.wav") + ".txt").unlink(missing_ok=True)
        print(f"{len(buruk)} dihapus untuk diundi ulang", flush=True)
    elif buruk:
        print("Yang bertahan jelek sesudah beberapa putaran: tambahkan ejaan ganti di ejaan.json.", flush=True)


if __name__ == "__main__":
    main()
