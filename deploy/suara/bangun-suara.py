#!/usr/bin/env python3
"""Bangun berkas suara kiosk E-Manshurin dari voicebox.

Satu berkas per kalimat, dinamai sha1 kalimatnya. Nama itu dipilih supaya
templat yang diedit pengurus otomatis bikin berkas baru, dan supaya jamaah
yang sapaan+namanya kebetulan sama berbagi satu berkas.

Pemakaian: python3 bangun-suara.py jamaah.json keluaran/ [--profil ID]
"""
import hashlib
import json
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path

API = "http://127.0.0.1:17493"
BATAS_DETIK = 180


def panggil(jalan, muatan=None, mentah=False):
    data = json.dumps(muatan).encode() if muatan is not None else None
    hdr = {"Content-Type": "application/json"} if data else {}
    with urllib.request.urlopen(urllib.request.Request(API + jalan, data, hdr), timeout=BATAS_DETIK) as r:
        isi = r.read()

    return isi if mentah else json.loads(isi)


def status(gid):
    # Endpoint status itu SSE, bukan JSON biasa: baris pertama "data: {...}".
    isi = panggil(f"/generate/{gid}/status", mentah=True).decode(errors="replace")
    for baris in isi.splitlines():
        if baris.startswith("data: "):
            return json.loads(baris[6:])

    raise RuntimeError(f"status tak terbaca: {isi[:120]}")


def kalimat(templat, j):
    doa = "Alhamdulillah, Jazakallahu Khoiro" if j["jk"] == "L" else "Alhamdulillah, Jazakillahu Khoiro"

    return (templat
            .replace("{doa}", doa)
            .replace("{sapaan}", j["sapaan"])
            .replace("{nama}", j["nama"]))


def main():
    sumber, tujuan = Path(sys.argv[1]), Path(sys.argv[2])
    profil = sys.argv[sys.argv.index("--profil") + 1] if "--profil" in sys.argv else None
    tujuan.mkdir(parents=True, exist_ok=True)

    d = json.loads(sumber.read_text(encoding="utf-8"))
    templat = d["templat"]["suara_absen"]
    if not profil:
        profil = next(p["id"] for p in panggil("/profiles") if p["name"] == "Kiosk E-Manshurin")

    # Dedup di sini, bukan di pemanggilan: 319 jamaah tapi kalimatnya 311.
    teks = {}
    for j in d["jamaah"]:
        teks.setdefault(kalimat(templat, j), []).append(j["id"])

    peta, gagal = {}, []
    print(f"templat: {templat!r}", flush=True)
    print(f"{len(d['jamaah'])} jamaah -> {len(teks)} kalimat unik", flush=True)

    for n, (t, ids) in enumerate(sorted(teks.items()), 1):
        kunci = hashlib.sha1(t.encode()).hexdigest()[:16]
        peta[kunci] = {"teks": t, "jamaah": ids}
        berkas = tujuan / f"{kunci}.wav"
        if berkas.exists() and berkas.stat().st_size > 1000:
            continue

        mulai = time.monotonic()
        try:
            gid = panggil("/generate", {
                "profile_id": profil, "text": t, "language": "ms",
                "engine": "chatterbox", "seed": 7,
            })["id"]

            while True:
                s = status(gid)
                if s["status"] != "generating":
                    break
                if time.monotonic() - mulai > BATAS_DETIK:
                    raise TimeoutError(f"lewat {BATAS_DETIK}s")
                time.sleep(1)

            if s["status"] != "completed":
                raise RuntimeError(s.get("error") or s["status"])

            berkas.write_bytes(panggil(f"/audio/{gid}", mentah=True))
            print(f"{n}/{len(teks)} {kunci} {s['duration']:.1f}s  {t}", flush=True)
        except (urllib.error.URLError, OSError, RuntimeError, TimeoutError) as e:
            gagal.append({"teks": t, "galat": str(e)})
            print(f"{n}/{len(teks)} GAGAL {t} -- {e}", flush=True)

    (tujuan / "peta.json").write_text(
        json.dumps({"templat": templat, "berkas": peta}, ensure_ascii=False, indent=1),
        encoding="utf-8")

    print(f"selesai. gagal: {len(gagal)}", flush=True)
    if gagal:
        (tujuan / "gagal.json").write_text(json.dumps(gagal, ensure_ascii=False, indent=1), encoding="utf-8")
        sys.exit(1)


if __name__ == "__main__":
    main()
