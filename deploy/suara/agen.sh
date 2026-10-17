#!/bin/bash
# ─────────────────────────────────────────────────────────────────────────────
# Dijalankan dari Mac mini lewat kunci SSH yang dibatasi ke skrip ini saja.
#
# Kenapa dibatasi: cron suara butuh Mac mini menjangkau server, tapi memberi
# mesin rumah kunci root penuh ke VPS produksi itu harga yang terlalu mahal
# untuk menyalin beberapa berkas mp3. Dengan command= di authorized_keys,
# kunci itu cuma bisa dua hal di bawah ini, apa pun yang diminta pengirimnya.
#
# Pasang di authorized_keys server (satu baris). Dipanggil lewat /bin/bash,
# bukan langsung: exec bit tidak terbawa dari checkout Windows, jadi setiap deploy
# akan mengembalikannya ke 644 dan sshd menolak menjalankannya.
#   restrict,command="/bin/bash /www/wwwroot/emanshurin/deploy/suara/agen.sh" ssh-ed25519 AAAA... macmini
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

APP=/www/wwwroot/emanshurin
SUARA="$APP/backend/storage/app/public/suara"

case "${SSH_ORIGINAL_COMMAND:-}" in
  dump)
    # Daftar jamaah aktif + templat ucapan, buat menentukan kalimat apa saja yang perlu ada.
    cd "$APP/backend"
    php artisan tinker "$APP/deploy/suara/dump-jamaah.php" < /dev/null > /dev/null 2>&1
    cat /tmp/suara-jamaah.json
    rm -f /tmp/suara-jamaah.json
    ;;

  punya)
    # Nama berkas yang sudah ada, supaya Mac mini tidak mengirim ulang 7 MB tiap malam.
    cd "$SUARA" && ls -1 *.mp3 2>/dev/null || true
    ;;

  terima)
    # tar.gz dari stdin. Hanya mp3 yang diterima: tanpa saringan ini, kunci yang
    # dibatasi pun bisa menaruh berkas apa saja di direktori yang dilayani nginx.
    tmp=$(mktemp -d)
    trap 'rm -rf "$tmp"' EXIT
    tar -xzf - -C "$tmp"
    find "$tmp" -type f ! -name '*.mp3' -delete
    find "$tmp" -type f -name '*.mp3' -exec mv -f {} "$SUARA/" \;
    cd "$SUARA"
    chown www:www *.mp3
    chmod 644 *.mp3
    ls -1 *.mp3 | wc -l
    ;;

  *)
    echo "agen.sh: cuma menerima dump, punya, atau terima" >&2
    exit 1
    ;;
esac
