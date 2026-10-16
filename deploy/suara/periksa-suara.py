#!/usr/bin/env python3
"""Periksa berkas suara kiosk dengan membalik-transkripnya, lalu generate ulang yang jelek.

Chatterbox tidak stabil untuk teks pendek: satu putaran bisa menghasilkan "masuk"
saja tanpa namanya. Itu tidak kelihatan dari berkasnya, jadi tiap berkas dibaca
ulang whisper dan dibandingkan dengan teks yang diminta.

Transkrip whisper untuk klip 2 detik ejaannya sering ngawur ("Sakha" -> "saha"),
jadi yang dipakai kemiripan, bukan kecocokan persis. Ambangnya memisahkan salah
eja (0,85+) dari nama yang hilang sama sekali (0,55).

Pemakaian: python3 periksa-suara.py keluaran/ [--ambang 0.7] [--ulang 3]
"""
import difflib
import json
import re
import subprocess
import sys
import time
import urllib.request
from pathlib import Path

API = "http://127.0.0.1:17493"
WHISPER = "/opt/homebrew/bin/whisper-cli"
MODEL = Path.home() / "whisper/model/ggml-large-v3-turbo.bin"
PROFIL = "Kiosk E-Manshurin"


def argumen(nama, bawaan):
    return type(bawaan)(sys.argv[sys.argv.index(nama) + 1]) if nama in sys.argv else bawaan


def huruf(s):
    return re.sub(r"[^a-z]", "", s.lower())


def nilai(diminta, terdengar):
    return difflib.SequenceMatcher(None, huruf(diminta), huruf(terdengar)).ratio()


def transkrip(berkas):
    """Satu panggilan whisper untuk semua berkas — memuat model 1,6 GB sekali, bukan 311 kali."""
    baru = [b for b in berkas if not Path(str(b) + ".txt").exists()]
    if baru:
        subprocess.run([WHISPER, "-m", str(MODEL), "-l", "id", "-nt", "-otxt", *map(str, baru)],
                       check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

    return {b.stem: Path(str(b) + ".txt").read_text(encoding="utf-8", errors="replace").strip()
            for b in berkas}


def panggil(jalan, muatan=None, mentah=False):
    data = json.dumps(muatan).encode() if muatan is not None else None
    hdr = {"Content-Type": "application/json"} if data else {}
    with urllib.request.urlopen(urllib.request.Request(API + jalan, data, hdr), timeout=180) as r:
        isi = r.read()

    return isi if mentah else json.loads(isi)


def status(gid):
    isi = panggil(f"/generate/{gid}/status", mentah=True).decode(errors="replace")
    for baris in isi.splitlines():
        if baris.startswith("data: "):
            return json.loads(baris[6:])

    raise RuntimeError("status tak terbaca")


def generate(profil, teks, seed):
    gid = panggil("/generate", {"profile_id": profil, "text": teks, "language": "ms",
                                "engine": "chatterbox", "seed": seed})["id"]
    batas = time.monotonic() + 180
    while True:
        s = status(gid)
        if s["status"] != "generating":
            break
        if time.monotonic() > batas:
            raise TimeoutError("generate kelewat lama")
        time.sleep(1)

    if s["status"] != "completed":
        raise RuntimeError(s.get("error") or s["status"])

    return panggil(f"/audio/{gid}", mentah=True)


def main():
    tujuan = Path(sys.argv[1])
    ambang = argumen("--ambang", 0.7)
    ulang = argumen("--ulang", 3)

    peta = json.loads((tujuan / "peta.json").read_text(encoding="utf-8"))["berkas"]
    profil = next(p["id"] for p in panggil("/profiles") if p["name"] == PROFIL)

    wav = sorted(tujuan.glob("*.wav"))
    dengar = transkrip(wav)

    # Dikunci per berkas, bukan daftar: baris yang sama ditimpa sesudah generate
    # ulang, supaya laporannya memuat nilai akhir — bukan nilai sebelum diperbaiki.
    buruk, laporan = [], {}
    for b in wav:
        diminta = peta.get(b.stem, {}).get("teks")
        if not diminta:
            continue
        n = nilai(diminta, dengar[b.stem])
        laporan[b.stem] = {"kunci": b.stem, "teks": diminta, "terdengar": dengar[b.stem],
                           "nilai": round(n, 3)}
        if n < ambang:
            buruk.append((b, diminta, n))

    print(f"{len(wav)} berkas diperiksa, {len(buruk)} di bawah ambang {ambang}", flush=True)

    for b, diminta, n_awal in buruk:
        terbaik = (n_awal, b.read_bytes(), dengar[b.stem])
        for seed in range(100, 100 + ulang):
            try:
                isi = generate(profil, diminta, seed)
            except (OSError, RuntimeError, TimeoutError) as e:
                print(f"  {b.stem} seed {seed} gagal: {e}", flush=True)
                continue

            coba = tujuan / f"coba-{b.stem}.wav"
            coba.write_bytes(isi)
            Path(str(coba) + ".txt").unlink(missing_ok=True)
            terdengar = transkrip([coba])[coba.stem]
            n = nilai(diminta, terdengar)
            coba.unlink(missing_ok=True)
            Path(str(coba) + ".txt").unlink(missing_ok=True)

            if n > terbaik[0]:
                terbaik = (n, isi, terdengar)
            if n >= ambang:
                break

        b.write_bytes(terbaik[1])
        Path(str(b) + ".txt").unlink(missing_ok=True)
        laporan[b.stem] |= {"nilai": round(terbaik[0], 3), "terdengar": terbaik[2],
                            "nilai_awal": round(n_awal, 3)}
        tanda = "OK" if terbaik[0] >= ambang else "MASIH JELEK"
        print(f"  {b.stem} {n_awal:.2f} -> {terbaik[0]:.2f} {tanda}  {diminta}", flush=True)

    (tujuan / "laporan.json").write_text(
        json.dumps(sorted(laporan.values(), key=lambda r: r["nilai"]), ensure_ascii=False, indent=1),
        encoding="utf-8")
    print("laporan.json ditulis", flush=True)


if __name__ == "__main__":
    main()
