#!/bin/bash
# ─────────────────────────────────────────────────────────────────────────────
# Jalan tiap malam di Mac mini: bangun berkas suara untuk jamaah yang belum
# punya, lalu kirim ke server.
#
# Arahnya sengaja dari rumah ke server, bukan sebaliknya. Server itu terbuka ke
# internet; memberinya kunci masuk ke mesin rumah demi menyalin mp3 tidak
# sebanding. Kunci Mac mini di sisi server pun dibatasi ke deploy/suara/agen.sh.
#
# Mac mini mati, tidur, atau voicebox-nya belum siap? tidak ada yang rusak --
# berkasnya belum muncul, jamaah itu disapa suara bawaan perangkat, dan malam
# berikutnya dicoba lagi.
#
# Pasang (launchd, jam 2 pagi):
#   cp deploy/suara/sh.voicebox.malam.plist ~/Library/LaunchAgents/
#   launchctl bootstrap gui/$(id -u) ~/Library/LaunchAgents/sh.voicebox.malam.plist
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

# launchd tidak membawa PATH homebrew
export PATH="/opt/homebrew/bin:/usr/bin:/bin:/usr/sbin:/sbin"

KERJA="$HOME/voicebox-app"
SERVER="root@100.94.76.103"   # alamat tailscale aapanel
API="http://127.0.0.1:17493"

lapor() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1"; }

cd "$KERJA"

curl -s -m 10 "$API/health" > /dev/null 2>&1 || { lapor "voicebox belum siap, dicoba besok"; exit 0; }

ssh -o BatchMode=yes -o ConnectTimeout=20 "$SERVER" dump > jamaah.json.baru
# Ditukar sesudah utuh: dump yang terpotong di tengah jaringan jangan sampai
# menimpa daftar yang masih sehat.
[ -s jamaah.json.baru ] || { lapor "dump kosong, daftar lama dipertahankan"; rm -f jamaah.json.baru; exit 1; }
mv jamaah.json.baru jamaah.json

python3 bangun-suara.py jamaah.json keluaran/
python3 periksa-suara.py keluaran/

ssh -o BatchMode=yes -o ConnectTimeout=20 "$SERVER" punya > punya.txt

KIRIM=$(mktemp -d)
trap 'rm -rf "$KIRIM"' EXIT

for f in keluaran/*.wav; do
  b=$(basename "$f" .wav)
  grep -qx "$b.mp3" punya.txt && continue
  ffmpeg -nostdin -v error -y -i "$f" \
    -af "loudnorm=I=-16:TP=-1.5:LRA=11" -codec:a libmp3lame -b:a 64k -ac 1 "$KIRIM/$b.mp3"
done

JUMLAH=$(find "$KIRIM" -name '*.mp3' | wc -l | tr -d ' ')
if [ "$JUMLAH" = "0" ]; then
  lapor "tidak ada kalimat baru"
  exit 0
fi

TOTAL=$(tar -czf - -C "$KIRIM" . | ssh -o BatchMode=yes -o ConnectTimeout=20 "$SERVER" terima)
lapor "$JUMLAH kalimat baru terkirim, server sekarang punya $TOTAL berkas"
