#!/bin/bash
# ─────────────────────────────────────────────────────────────────────────────
# Cadangan harian E-Manshurin: basis data + foto wajah.
#
# Yang paling mahal kalau server ini hilang bukan datanya, tapi memotret ulang
# 321 orang. Mengumpulkan foto wajah itu berminggu-minggu: HP mati di tengah
# jalan, wajah tidak terdeteksi, foto nyasar ke kartu yang salah.
#
# Cron (jam 3 pagi WIB, sesudah penerbit kegiatan jam 1 dan pembersih log jam 2):
#   0 3 * * * bash /www/wwwroot/emanshurin/deploy/backup.sh >> /var/log/emanshurin-backup.log 2>&1
#
# MEMULIHKAN:
#   gunzip -c emanshurin-2026-10-16.sql.gz | PGPASSWORD=xxx psql -h 127.0.0.1 -U emanshurin -d emanshurin
#   rsync -a /www/wwwroot/emanshurin-backup/foto/ \
#            /www/wwwroot/emanshurin/backend/storage/app/public/jamaah/
# ─────────────────────────────────────────────────────────────────────────────
set -uo pipefail

APP="/www/wwwroot/emanshurin"
TUJUAN="/www/wwwroot/emanshurin-backup"
MEGA_DIR="/emanshurin-backup"
RETENSI_HARI=14
# Dump yang lebih kecil dari ini pasti gagal, bukan "kebetulan datanya sedikit".
MINIMAL_BYTE=20000

TANGGAL=$(date +%Y-%m-%d)
WAKTU=$(date '+%Y-%m-%d %H:%M:%S')

env_get() { grep -E "^$1=" "$APP/backend/.env" | head -1 | cut -d= -f2- | tr -d '"'"'"''; }

lapor() { echo "[$WAKTU] $1"; }

# Telegram kalau kuncinya memang dipasang di .env; kalau tidak, diam saja.
# Tanpa ini kegagalan cadangan cuma mengendap di berkas log yang tidak pernah dibuka.
kabari() {
  local pesan="$1"
  local token chat
  token=$(env_get TELEGRAM_BOT_TOKEN)
  chat=$(env_get TELEGRAM_CHAT_ID)
  [ -z "$token" ] || [ -z "$chat" ] && return 0

  curl -s -o /dev/null -X POST "https://api.telegram.org/bot${token}/sendMessage" \
    -d chat_id="$chat" --data-urlencode "text=$pesan"
}

gagal() {
  lapor "GAGAL: $1"
  kabari "❌ Cadangan E-Manshurin gagal — $1 ($WAKTU)"
  exit 1
}

mkdir -p "$TUJUAN/db" "$TUJUAN/foto"

# ── 1. Basis data ────────────────────────────────────────────────────────────
BERKAS="$TUJUAN/db/emanshurin-$TANGGAL.sql.gz"

PGPASSWORD="$(env_get DB_PASSWORD)" pg_dump \
  -h "$(env_get DB_HOST)" \
  -p "$(env_get DB_PORT)" \
  -U "$(env_get DB_USERNAME)" \
  -d "$(env_get DB_DATABASE)" \
  --no-owner --no-privileges 2>/dev/null | gzip > "$BERKAS"

[ -s "$BERKAS" ] || gagal "pg_dump tidak menghasilkan apa-apa"
gzip -t "$BERKAS" 2>/dev/null || gagal "berkas dump rusak"

UKURAN=$(stat -c %s "$BERKAS")
[ "$UKURAN" -ge "$MINIMAL_BYTE" ] || gagal "dump cuma $UKURAN byte, kemungkinan basis datanya tidak terbaca"

lapor "dump basis data: $(du -h "$BERKAS" | cut -f1)"

# ── 2. Foto wajah ────────────────────────────────────────────────────────────
# Tanpa --delete, sengaja: cadangan ini juga menjaga dari penghapusan yang tidak
# disengaja. Foto yatim di salinan ini cuma memakan ruang; foto yang hilang
# berarti memotret ulang orangnya.
rsync -a "$APP/backend/storage/app/public/jamaah/" "$TUJUAN/foto/" \
  || gagal "rsync foto wajah"

lapor "foto wajah: $(du -sh "$TUJUAN/foto" | cut -f1)"

# ── 3. Salinan di luar server ────────────────────────────────────────────────
# Cadangan yang tidur di disk yang sama dengan aslinya cuma melindungi dari
# kesalahan manusia, bukan dari matinya server.
if command -v mega-put >/dev/null 2>&1 && mega-whoami >/dev/null 2>&1; then
  mega-mkdir -p "$MEGA_DIR/db" >/dev/null 2>&1
  mega-put -c "$BERKAS" "$MEGA_DIR/db/" >/dev/null 2>&1 \
    && lapor "dump terkirim ke MEGA" \
    || lapor "PERINGATAN: gagal mengirim dump ke MEGA"

  # Foto cuma sepekan sekali: 171 MB tiap hari ke luar server itu pemborosan,
  # dan fotonya hampir tidak pernah berubah sesudah seorang jamaah terdaftar.
  if [ "$(date +%u)" = "7" ]; then
    TAR="$TUJUAN/foto-$TANGGAL.tar.gz"
    tar -czf "$TAR" -C "$TUJUAN" foto 2>/dev/null \
      && mega-mkdir -p "$MEGA_DIR/foto" >/dev/null 2>&1 \
      && mega-put -c "$TAR" "$MEGA_DIR/foto/" >/dev/null 2>&1 \
      && lapor "foto pekanan terkirim ke MEGA: $(du -h "$TAR" | cut -f1)"
    rm -f "$TAR"
  fi
else
  lapor "PERINGATAN: MEGA tidak tersedia, cadangan hanya ada di server ini"
fi

# ── 4. Buang yang kedaluwarsa ────────────────────────────────────────────────
find "$TUJUAN/db" -name 'emanshurin-*.sql.gz' -mtime "+$RETENSI_HARI" -delete

JUMLAH=$(find "$TUJUAN/db" -name 'emanshurin-*.sql.gz' | wc -l)
lapor "selesai — $JUMLAH dump tersimpan, retensi $RETENSI_HARI hari"
kabari "✅ Cadangan E-Manshurin — $(du -h "$BERKAS" | cut -f1) basis data, $(du -sh "$TUJUAN/foto" | cut -f1) foto ($WAKTU)"
