#!/usr/bin/env python3
"""Bangun berkas suara kiosk E-Manshurin: Piper untuk lafal, Seed-VC untuk suaranya.

Kenapa dua tahap, bukan satu model kloning seperti sebelumnya: tidak ada mesin
kloning sumber terbuka yang bahasanya Indonesia. Chatterbox dijalankan di mode
Melayu, dan hasilnya "coba" terbaca "khoba", angka rusak, serta 7% kalimat keluar
ngawur secara acak. Memisah pekerjaannya menyelesaikan ketiganya sekaligus:

  Piper (id_ID)  lafal Indonesia benar, dan deterministik -- teks sama, hasil
                 sama, selamanya. Tidak ada lagi yang perlu diundi ulang.
  Seed-VC        memindahkan warna suara dari rekaman rujukan ke atasnya.

Terukur pada 311 kalimat yang sama: kemiripan transkrip 0,967 (Chatterbox 0,897),
kemiripan penutur 0,885 (Chatterbox 0,795; rekaman asli orangnya sendiri 0,947).

Nama berkas tetap sha1 kalimat ASLI dari basis data, bukan kalimat yang sudah
dinormalkan ejaannya. Jadi kiosk tidak perlu tahu mesinnya berganti.

Pemakaian: python3 bangun-suara.py jamaah.json keluaran/ [--rujukan a.wav]
"""
import hashlib
import json
import re
import shutil
import subprocess
import sys
import tempfile
import types
from pathlib import Path

RUMAH = Path.home()
SEEDVC = RUMAH / "seed-vc"
PIPER = RUMAH / "piper-uji/.venv/bin/piper"
SUARA_PIPER = RUMAH / "piper-uji/suara/id_ID-news_tts-medium.onnx"
RUJUKAN = RUMAH / "whisper/ref-a.wav"
LANGKAH = 30
PANJANG = 1.0
CFG = 0.7


def argumen(nama, bawaan):
    return sys.argv[sys.argv.index(nama) + 1] if nama in sys.argv else bawaan


def kalimat(templat, j):
    doa = "Alhamdulillah, Jazakallahu Khoiro" if j["jk"] == "L" else "Alhamdulillah, Jazakillahu Khoiro"

    return (templat
            .replace("{doa}", doa)
            .replace("{sapaan}", j["sapaan"])
            .replace("{nama}", j["nama"]))


def untuk_diucapkan(teks, ejaan):
    """Teks yang dikirim ke Piper -- bukan yang dipakai menghitung sha1.

    Dua hal: nama KAPITAL dibaca espeak seperti singkatan ("AKMAL MBAPPE" jadi
    "a-ka-em-a-el"), jadi dijadikan Title Case; dan sebagian nama kalah oleh
    aturan ejaan espeak berapa pun kapitalnya, jadi ada daftar ganti.
    """
    for asli, ganti in ejaan.items():
        if not asli.startswith("_"):
            teks = re.sub(rf"\b{re.escape(asli)}\b", ganti, teks)

    return re.sub(r"\b[A-Z]{2,}(?:'[A-Z]+)?\b", lambda m: m.group(0).title(), teks)


def muat_seedvc():
    """Model dimuat sekali, bukan tiap kalimat.

    inference.py memuat ulang semuanya di awal main(), dan itu ~20 detik. Untuk
    311 kalimat artinya satu jam terbuang hanya untuk memuat. load_models-nya
    diganti dengan versi yang menyimpan hasilnya, supaya sisa kodenya tetap
    dipakai apa adanya -- bukan ditulis ulang.
    """
    sys.path.insert(0, str(SEEDVC))
    import inference  # noqa: E402 -- butuh sys.path di atas

    simpanan = {}
    asli = inference.load_models

    def sekali(args):
        if "model" not in simpanan:
            simpanan["model"] = asli(args)

        return simpanan["model"]

    inference.load_models = sekali

    return inference


def args_vc(sumber, rujukan, keluar):
    return types.SimpleNamespace(
        source=str(sumber), target=str(rujukan), output=str(keluar),
        diffusion_steps=LANGKAH, length_adjust=PANJANG, inference_cfg_rate=CFG,
        semi_tone_shift=0, checkpoint=None, config=None,
        auto_f0_adjust=False, f0_condition=False, fp16=True,
    )


def main():
    sumber, tujuan = Path(sys.argv[1]), Path(sys.argv[2])
    rujukan = Path(argumen("--rujukan", RUJUKAN))
    tujuan.mkdir(parents=True, exist_ok=True)

    ejaan = json.loads((Path(__file__).parent / "ejaan.json").read_text(encoding="utf-8"))
    d = json.loads(sumber.read_text(encoding="utf-8"))
    templat = d["templat"]["suara_absen"]

    # Dedup di sini: 319 jamaah, 311 kalimat unik.
    teks = {}
    for j in d["jamaah"]:
        teks.setdefault(kalimat(templat, j), []).append(j["id"])

    kurang = {t: k for t, k in
              ((t, hashlib.sha1(t.encode()).hexdigest()[:16]) for t in teks)
              if not (tujuan / f"{k}.wav").exists()}

    peta = {hashlib.sha1(t.encode()).hexdigest()[:16]: {"teks": t, "jamaah": ids}
            for t, ids in teks.items()}
    (tujuan / "peta.json").write_text(
        json.dumps({"templat": templat, "berkas": peta}, ensure_ascii=False, indent=1),
        encoding="utf-8")

    print(f"templat: {templat!r}", flush=True)
    print(f"{len(d['jamaah'])} jamaah -> {len(teks)} kalimat unik, {len(kurang)} belum ada", flush=True)
    if not kurang:
        return

    inference = muat_seedvc()
    gagal = []

    with tempfile.TemporaryDirectory() as tmp:
        tmp = Path(tmp)
        for n, (t, kunci) in enumerate(sorted(kurang.items(), key=lambda x: x[1]), 1):
            mentah = tmp / f"{kunci}.wav"
            try:
                subprocess.run([str(PIPER), "-m", str(SUARA_PIPER), "-f", str(mentah)],
                               input=untuk_diucapkan(t, ejaan).encode(), check=True,
                               stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

                inference.main(args_vc(mentah, rujukan, tmp))
                jadi = tmp / f"vc_{kunci}_{rujukan.stem}_{PANJANG}_{LANGKAH}_{CFG}.wav"
                shutil.move(str(jadi), tujuan / f"{kunci}.wav")
                print(f"{n}/{len(kurang)} {kunci}  {t}", flush=True)
            except (OSError, RuntimeError, subprocess.CalledProcessError) as e:
                gagal.append({"teks": t, "galat": str(e)})
                print(f"{n}/{len(kurang)} GAGAL {t} -- {e}", flush=True)

    print(f"selesai. gagal: {len(gagal)}", flush=True)
    if gagal:
        (tujuan / "gagal.json").write_text(json.dumps(gagal, ensure_ascii=False, indent=1), encoding="utf-8")
        sys.exit(1)


if __name__ == "__main__":
    main()
