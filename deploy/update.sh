#!/bin/bash
# ─────────────────────────────────────────────────────────────────
# E-Manshurin — Update Script
# Jalankan di server setiap kali ada update dari GitHub:
#   bash /www/wwwroot/emanshurin/deploy/update.sh
# ─────────────────────────────────────────────────────────────────

set -e
APP_DIR="/www/wwwroot/emanshurin"
DOMAIN="emanshurin.kreasikaryaarjuna.co.id"
WEB_PORT=3005
FACE_PORT=5001

PHP="/usr/bin/php8.3"
[ ! -x "$PHP" ] && PHP="$(command -v php8.3 2>/dev/null || command -v php)"

NODE_DIR="$(dirname "$(find /www/server/nodejs -maxdepth 2 -name node -type f 2>/dev/null | sort -V | tail -1)")"
[ -z "$NODE_DIR" ] || [ "$NODE_DIR" = "." ] && NODE_DIR="/usr/bin"
NODE="$NODE_DIR/node"
NPM="$NODE_DIR/npm"
export PATH="$NODE_DIR:$PATH"

STANDALONE_DIR="$APP_DIR/web/.next/standalone"
STANDALONE_BAK="$APP_DIR/web/.next/standalone.bak"
STANDALONE_SERVER="$STANDALONE_DIR/server.js"

cd "$APP_DIR"
echo "=== E-Manshurin Update ==="

# ── 1. Pull latest code ───────────────────────────────────────────
echo "[1/6] Git fetch + reset..."
git fetch origin main
git reset --hard origin/main

# ── 2. Backend update ────────────────────────────────────────────
echo "[2/6] Backend update..."
cd "$APP_DIR/backend"
COMPOSER=$(command -v composer 2>/dev/null || echo "/www/server/composer/composer.phar")
$PHP $COMPOSER install --no-dev --optimize-autoloader --no-interaction
$PHP artisan migrate --force
$PHP artisan config:cache
$PHP artisan route:cache
chown -R www:www "$APP_DIR/backend/storage/" 2>/dev/null || true
chmod -R 775 "$APP_DIR/backend/storage/"
systemctl reload php8.3-fpm 2>/dev/null || /etc/init.d/php-fpm-83 reload 2>/dev/null || true

# ── 3. Face service deps ──────────────────────────────────────────
echo "[3/6] Face-service deps..."
cd "$APP_DIR/face-service"
./venv/bin/pip install -r requirements.txt -q
pm2 restart emanshurin-face 2>/dev/null || \
    pm2 start ./venv/bin/uvicorn --name emanshurin-face --interpreter none \
        --cwd "$APP_DIR/face-service" -- server:app --host 127.0.0.1 --port $FACE_PORT

# ── 4. Frontend build (backup dulu utk rollback) ──────────────────
echo "[4/6] Frontend build..."
if [ -f "$STANDALONE_SERVER" ]; then
    rm -rf "$STANDALONE_BAK"
    rsync -a "$STANDALONE_DIR/" "$STANDALONE_BAK/" 2>/dev/null || true
fi

cd "$APP_DIR/web"
export NEXT_PUBLIC_API_URL="https://$DOMAIN/api"
$NPM install
rm -rf .next
$NPM run build

if [ ! -f "$STANDALONE_SERVER" ]; then
    echo "❌ ERROR: build gagal, standalone/server.js tidak ada. Server lama tetap jalan."
    exit 1
fi
rsync -a .next/static/ .next/standalone/.next/static/ || true
rsync -a public/ .next/standalone/public/ || true

# ── 5. Restart PM2 web + health check + rollback ──────────────────
echo "[5/6] Restart PM2 web..."
pm2 restart emanshurin-web 2>/dev/null || \
    PORT=$WEB_PORT pm2 start "$STANDALONE_SERVER" --name emanshurin-web --env production

sleep 8
STATUS=$(pm2 jlist 2>/dev/null | python3 -c "
import sys, json
procs = json.load(sys.stdin)
for p in procs:
    if p.get('name') == 'emanshurin-web':
        print(p.get('pm2_env', {}).get('status', 'unknown'))
        break
" 2>/dev/null || echo "unknown")

if [ "$STATUS" != "online" ]; then
    echo "🔴 HEALTH CHECK GAGAL — status: $STATUS. Rollback..."
    if [ -f "$STANDALONE_BAK/server.js" ]; then
        pm2 stop emanshurin-web || true
        rsync -a --delete "$STANDALONE_BAK/" "$STANDALONE_DIR/" 2>/dev/null || true
        pm2 start emanshurin-web
        echo "✅ Rollback selesai — server lama kembali online."
    else
        echo "❌ Tidak ada backup untuk rollback. Cek: pm2 logs emanshurin-web"
    fi
    exit 1
fi
echo "  ✓ Website sehat — PM2 online"
rm -rf "$STANDALONE_BAK" 2>/dev/null || true

# ── 6. Purge nginx proxy cache ─────────────────────────────────────
echo "[6/6] Purge nginx proxy cache..."
rm -rf /www/server/nginx/proxy_cache_dir/* 2>/dev/null || true
/www/server/nginx/sbin/nginx -s reload 2>/dev/null || /etc/init.d/nginx reload 2>/dev/null || true

echo ""
echo "✅ Update selesai!"
pm2 save
pm2 status
