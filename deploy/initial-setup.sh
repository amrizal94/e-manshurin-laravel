#!/bin/bash
# ═══════════════════════════════════════════════════════════════════
# E-Manshurin — Initial Setup Script (Ubuntu 22.04 + aaPanel)
# Server sudah dipakai banyak project lain (facehrm, hotel, sico, wa
# gateway) — script ini HANYA menambah, tidak menyentuh service lain.
# Jalankan SEKALI setelah:
#   1. DNS A record emanshurin.kreasikaryaarjuna.co.id sudah pointing
#      ke 45.66.153.156 (Cloudflare)
#   2. Web root awal + SSL sudah dibuat via aapanel/scripts/setup-new-site.sh
# Usage: DB_PASSWORD=xxx bash initial-setup.sh
# ═══════════════════════════════════════════════════════════════════

set -e

DOMAIN="emanshurin.kreasikaryaarjuna.co.id"
DB_PASSWORD="${DB_PASSWORD:-GANTI_PASSWORD_KUAT}"
WA_DEVICE_API_KEY="${WA_DEVICE_API_KEY:-}"

APP_DIR="/www/wwwroot/emanshurin"
REPO_URL="${REPO_URL:-git@github.com:amrizal94/e-manshurin-laravel.git}"
PHP="/www/server/php/83/bin/php"
PHP_FPM_SOCK="/tmp/php-cgi-83.sock"
NODE_DIR="$(dirname "$(find /www/server/nodejs -maxdepth 2 -name node -type f 2>/dev/null | sort -V | tail -1)")"
NODE="$NODE_DIR/node"
NPM="$NODE_DIR/npm"
WEB_PORT=3005
FACE_PORT=5001

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'
info()  { echo -e "${GREEN}[INFO]${NC} $1"; }
warn()  { echo -e "${YELLOW}[WARN]${NC} $1"; }
error() { echo -e "${RED}[ERROR]${NC} $1"; exit 1; }
ok()    { echo -e "${GREEN}✓${NC} $1"; }

echo ""
echo "═══════════════════════════════════════════════════════"
echo "  E-Manshurin — Initial Setup ($DOMAIN)"
echo "═══════════════════════════════════════════════════════"
echo ""

[ "$DB_PASSWORD" = "GANTI_PASSWORD_KUAT" ] && error "Set DB_PASSWORD dulu! DB_PASSWORD=rahasia123 bash initial-setup.sh"

# ── Cek port bentrok dengan service lain di server ────────────────
info "Cek port $WEB_PORT & $FACE_PORT tidak dipakai proses lain..."
for p in $WEB_PORT $FACE_PORT; do
    if pm2 jlist 2>/dev/null | grep -q "\"PORT\":\"*$p\"*" || ss -ltnp 2>/dev/null | grep -q ":$p "; then
        error "Port $p sudah dipakai proses lain — cek 'pm2 list' dan 'ss -ltnp', ganti WEB_PORT/FACE_PORT di script ini."
    fi
done
ok "Port $WEB_PORT (web) & $FACE_PORT (face-service) bebas"

[ ! -f "$PHP" ] && error "PHP 8.3 tidak ditemukan di $PHP. Install dulu via aaPanel App Store (sama seperti facehrm)."
ok "PHP: $($PHP --version | head -1)"
[ ! -f "$NODE" ] && error "Node.js tidak ditemukan di /www/server/nodejs — install dulu via aaPanel."
ok "Node.js: $($NODE --version)"
command -v psql &>/dev/null || error "PostgreSQL belum ada. Server ini seharusnya sudah punya (dari setup facehrm)."
ok "PostgreSQL: $(psql --version)"
command -v pm2 &>/dev/null || { info "Install PM2..."; $NPM install -g pm2; }
ok "PM2: $(pm2 --version)"
command -v python3 &>/dev/null || error "python3 tidak ditemukan — install dulu (apt-get install python3 python3-venv)."
ok "Python: $(python3 --version)"

# ── Database (reuse instance Postgres yang sama dgn facehrm) ─────
info "Setup database emanshurin..."
sudo -u postgres psql <<SQL
DO \$\$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'emanshurin_user') THEN
        CREATE USER emanshurin_user WITH PASSWORD '$DB_PASSWORD';
    ELSE
        ALTER USER emanshurin_user WITH PASSWORD '$DB_PASSWORD';
    END IF;
END
\$\$;

SELECT 'CREATE DATABASE emanshurin OWNER emanshurin_user'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'emanshurin')\gexec

GRANT ALL PRIVILEGES ON DATABASE emanshurin TO emanshurin_user;
SQL
ok "Database emanshurin + user emanshurin_user siap"

# ── Clone repo ─────────────────────────────────────────────────────
info "Clone repository..."
if [ -d "$APP_DIR/.git" ]; then
    cd "$APP_DIR" && git fetch origin main && git reset --hard origin/main
else
    git clone "$REPO_URL" "$APP_DIR"
fi
ok "Repository di $APP_DIR"

# ── Backend .env ───────────────────────────────────────────────────
info "Setup backend .env..."
if [ ! -f "$APP_DIR/backend/.env" ]; then
    cat > "$APP_DIR/backend/.env" << EOF
APP_NAME=E-Manshurin
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://$DOMAIN
APP_TIMEZONE=Asia/Jakarta

LOG_CHANNEL=stack
LOG_LEVEL=error

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=emanshurin
DB_USERNAME=emanshurin_user
DB_PASSWORD=$DB_PASSWORD

SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database
FILESYSTEM_DISK=local

SANCTUM_STATEFUL_DOMAINS=$DOMAIN
FRONTEND_URL=https://$DOMAIN

FACE_SERVICE_URL=http://127.0.0.1:$FACE_PORT
FACE_MATCH_THRESHOLD=0.40

WA_GATEWAY_URL=https://wa.kreasikaryaarjuna.co.id
WA_DEVICE_API_KEY=$WA_DEVICE_API_KEY
EOF
    ok ".env dibuat (isi WA_DEVICE_API_KEY manual jika kosong — lihat README)"
else
    ok ".env sudah ada (tidak ditimpa)"
fi

# ── Backend: composer + migrate ────────────────────────────────────
info "Backend setup (composer + migrate)..."
cd "$APP_DIR/backend"
COMPOSER=$(command -v composer 2>/dev/null || echo "/www/server/composer/composer.phar")
$PHP $COMPOSER install --no-dev --optimize-autoloader --no-interaction

APP_KEY_VAL=$(grep "^APP_KEY=" .env | cut -d'=' -f2 | tr -d ' ')
[ -z "$APP_KEY_VAL" ] && $PHP artisan key:generate --force && ok "APP_KEY generated"

$PHP artisan migrate --force
$PHP artisan db:seed --force 2>/dev/null || warn "Seeder skip (mungkin data sudah ada)"
$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan storage:link 2>/dev/null || true

chown -R www:www "$APP_DIR/backend/storage/" 2>/dev/null || true
chmod -R 775 "$APP_DIR/backend/storage/"
ok "Backend siap"

# ── Face service (Python venv) ─────────────────────────────────────
info "Setup face-service (Python venv)..."
cd "$APP_DIR/face-service"
python3 -m venv venv
./venv/bin/pip install --upgrade pip -q
./venv/bin/pip install -r requirements.txt -q
ok "face-service deps siap"

# ── Frontend build (Next.js standalone) ───────────────────────────
info "Frontend build (Next.js)..."
cd "$APP_DIR/web"
export PATH="$NODE_DIR:$PATH"
export NEXT_PUBLIC_API_URL="https://$DOMAIN/api"
$NPM install
$NPM run build
[ ! -f "$APP_DIR/web/.next/standalone/server.js" ] && error "Build Next.js gagal — standalone/server.js tidak ada"
rsync -a .next/static/ .next/standalone/.next/static/ || true
rsync -a public/ .next/standalone/public/ || true
ok "Frontend build selesai"

# ── Nginx: root + rewrite config ──────────────────────────────────
info "Update nginx config..."
VHOST_CONF="/www/server/panel/vhost/nginx/$DOMAIN.conf"
sed -i "s|root /www/wwwroot/$DOMAIN;|root $APP_DIR/backend/public;|" "$VHOST_CONF" 2>/dev/null || \
    warn "Tidak bisa update root di $VHOST_CONF otomatis — cek manual (harus point ke $APP_DIR/backend/public)"

mkdir -p /www/server/panel/vhost/rewrite
cp "$APP_DIR/deploy/nginx/rewrite.conf" "/www/server/panel/vhost/rewrite/$DOMAIN.conf"
# ganti path placeholder di rewrite.conf dgn APP_DIR & port aktual
sed -i "s|__APP_DIR__|$APP_DIR|g; s|__WEB_PORT__|$WEB_PORT|g; s|__FACE_PORT__|$FACE_PORT|g" \
    "/www/server/panel/vhost/rewrite/$DOMAIN.conf"

/www/server/nginx/sbin/nginx -t && /etc/init.d/nginx reload
ok "Nginx config updated & reloaded"

# ── PM2: face-service + web ───────────────────────────────────────
info "Start PM2 services..."
/etc/init.d/php-fpm-83 restart 2>/dev/null || systemctl restart php8.3-fpm 2>/dev/null || true

pm2 delete emanshurin-face 2>/dev/null || true
pm2 start "$APP_DIR/face-service/venv/bin/uvicorn" --name emanshurin-face --interpreter none \
    --cwd "$APP_DIR/face-service" -- server:app --host 127.0.0.1 --port $FACE_PORT

pm2 delete emanshurin-web 2>/dev/null || true
PORT=$WEB_PORT pm2 start "$APP_DIR/web/.next/standalone/server.js" --name emanshurin-web --env production

pm2 save
pm2 startup 2>/dev/null | tail -1 | bash 2>/dev/null || true

sleep 5
pm2 status

echo ""
echo "═══════════════════════════════════════════════════════"
echo "  ✅ E-Manshurin Setup Selesai!"
echo ""
echo "  Web : https://$DOMAIN"
echo "  API : https://$DOMAIN/api"
echo ""
echo "  Jika WA_DEVICE_API_KEY belum diisi: buat device baru di"
echo "  dashboard wa.kreasikaryaarjuna.co.id, set webhook_url ke"
echo "  https://$DOMAIN/api/wa/webhook, lalu isi .env manual + reload:"
echo "    php artisan config:cache && pm2 restart emanshurin-web"
echo ""
echo "  Debug: pm2 logs emanshurin-web / emanshurin-face"
echo "         tail -50 /www/wwwlogs/$DOMAIN.error.log"
echo "═══════════════════════════════════════════════════════"
