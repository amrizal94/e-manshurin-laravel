# E-Manshurin

Absensi pengajian berbasis pengenalan wajah, untuk struktur Daerah → Desa → Kelompok.
Jamaah cukup berdiri di depan kamera kiosk; yang berhalangan hadir bisa mengirim izin
lewat WhatsApp.

Produksi: <https://emanshurin.kreasikaryaarjuna.co.id>

## Isi repo

| Folder | Isi |
|---|---|
| `backend/` | API Laravel 13 (PHP 8.3), Sanctum, spatie/permission, spatie/activitylog |
| `web/` | Next.js 16 + React 19 + Tailwind 4 — panel admin dan halaman kiosk |
| `face-service/` | FastAPI + InsightFace ArcFace, mengubah foto jadi vektor 512 dimensi |
| `deploy/` | Skrip setup dan update untuk server aaPanel, plus konfigurasi nginx |
| `rencana awal.md` | Konsep awal dari pemilik sistem, disimpan sebagai rujukan |

Pencocokan wajah dilakukan di backend (cosine similarity terhadap descriptor tersimpan).
Browser hanya memakai `face-api.js` untuk mendeteksi *ada wajah atau tidak* sebelum foto
dikirim — deteksi ringan di sisi klien, pengenalan di sisi server.

## Konsep

**Struktur.** Satu daerah berisi beberapa desa, satu desa berisi beberapa kelompok.
Setiap akun terikat pada salah satu level; akun tanpa wilayah berarti super admin.
Akun kelompok melihat kegiatan kelompoknya sendiri **dan** kegiatan desa/daerah yang
mencakupnya, karena jamaahnya ikut jadi peserta di sana.

**Peran.** `super_admin`, `admin`, `absensi`. Peran `absensi` cukup untuk membuat
kegiatan, menjalankan kiosk, dan melihat rekap.

**Kegiatan.** Dibuat sebelum pengajian, dengan tanggal, jam mulai, jam selesai, jenis
pengajian, dan satu target struktur. Jenis pengajian menentukan siapa yang boleh absen:

| Jenis | Kategori usia peserta |
|---|---|
| `umum` | praremaja, remaja, usman, menikah |
| `caberawit` | paud_tk, caberawit |
| `praremaja` | praremaja |
| `remaja` | remaja |
| `usman` | usman |

**Kiosk standby.** `/absen-wajah` ditinggal menyala seharian. Server yang memilih kegiatan
mana yang sedang berlangsung dari jam sekarang, dengan toleransi 30 menit sebelum mulai
dan sesudah selesai. Kalau beberapa kegiatan jendelanya terbuka bersamaan, kandidat
diurutkan dari target paling spesifik (kelompok → desa → daerah) dan **wajah yang
menentukan** kegiatan mana yang dipakai: anak masuk pengajian caberawit, dewasa masuk
pengajian umum.

Di luar jam kegiatan kamera tetap menyala dan wajah tetap dikenali, tapi tidak ada yang
dicatat — kiosk hanya menyapa: *"Halo Mas Januar, saat ini belum ada kegiatan ya. Pengajian
berikutnya pukul 19.30."* Sapaan dibatasi sekali per orang tiap 2 menit, dan wajah asing
saat idle didiamkan saja.

`/kegiatan/{id}/absen-wajah` tetap ada untuk kiosk yang sengaja dikunci ke satu kegiatan.

**Bentrok kegiatan.** Dua kegiatan boleh berbarengan selama pesertanya tidak beririsan —
caberawit dan remaja di jam yang sama itu ruang berbeda. Yang ditolak: ada jamaah yang
masuk peserta keduanya, karena kiosk lalu harus menebak orangnya sedang duduk di mana.
Pengecekannya memakai irisan `pesertaQuery()`, jadi sekaligus menangkap "umum vs remaja"
(umum mencakup remaja) dan "kegiatan desa vs kegiatan kelompok di dalamnya".

**Izin lewat WhatsApp.** Format `izin <nama lengkap> <keterangan>`, contoh
`izin Januar Agung Hudiana kerja di Semarang`. Berlaku untuk kegiatan hari ini, dan kalau
hari ini kosong, untuk kegiatan besok (H-1). Detailnya di [Izin WhatsApp](#izin-whatsapp).

**Zona waktu.** Data disimpan UTC (`app.timezone`), tapi tanggal dan jam kegiatan diisi
dalam waktu setempat. Semua perbandingan "hari ini" dan "sedang berlangsung" memakai
`config('app.zona_lokal')` (default `Asia/Jakarta`). Jangan pakai `now()` polos untuk
membandingkan jadwal.

## Menjalankan secara lokal

Butuh PHP 8.3+, Composer, Node 20+, dan Python 3.10+.

### 1. Face service

```bash
cd face-service
python -m venv venv
venv/Scripts/pip install -r requirements.txt   # Linux/macOS: venv/bin/pip
venv/Scripts/python server.py                  # http://127.0.0.1:5000
```

Unduhan model InsightFace berjalan otomatis saat pertama kali dijalankan.

### 2. Backend

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan serve                              # http://127.0.0.1:8000
```

Seeder membuat peran, daerah "Kediri Selatan 1", dan satu akun super admin
`superadmin@e-manshurin.test` dengan password `password` — untuk lokal saja, ganti sebelum
dipakai di server.

Yang perlu diisi di `.env`:

| Variabel | Keterangan |
|---|---|
| `FACE_SERVICE_URL` | Alamat face-service, default `http://127.0.0.1:5000` |
| `FACE_MATCH_THRESHOLD` | Ambang cosine similarity, default `0.40` |
| `WA_GATEWAY_URL` | Gateway WhatsApp yang mengirim balasan |
| `WA_DEVICE_API_KEY` | Token gateway; kosongkan kalau tidak memakai izin WA |
| `APP_ZONA_LOKAL` | Zona waktu setempat, default `Asia/Jakarta` |

### 3. Web

```bash
cd web
npm install
echo "NEXT_PUBLIC_API_URL=http://127.0.0.1:8000/api" > .env.local
npm run dev                                    # http://localhost:3000
```

Model deteksi `face-api.js` harus ada di `web/public/models`.

### Test

```bash
cd backend && php artisan test
cd web && npx tsc --noEmit && npx eslint .
```

## Alur pemakaian

1. Susun struktur di **Struktur**: daerah, desa, kelompok.
2. Tambahkan jamaah di **Jamaah**, lalu daftarkan wajahnya — minimal 3 foto per orang.
3. Buat kegiatan di **Kegiatan**, lengkap dengan jam mulai dan jam selesai.
4. Buka **Absen Wajah** di perangkat kiosk, login pakai akun `absensi` milik kelompok itu,
   lalu tinggalkan menyala. Layar menampilkan wilayah akunnya supaya salah login ketahuan
   sebelum absen masuk ke wilayah yang keliru.
5. Pantau hasilnya di **Rekapitulasi**.

### Menyiapkan HP sebagai kiosk

Tidak perlu APK. Halaman kiosk menahan layar tetap menyala sendiri lewat Screen Wake Lock
selama halaman terbuka, jadi tidak usah menyetel "jangan pernah tidur" untuk seluruh HP.
Sisanya pakai bawaan Android:

- Buka `/absen-wajah` di Chrome → menu → **Tambahkan ke layar utama**. Manifest PWA-nya
  `standalone`, jadi terbuka layar penuh tanpa address bar dan ikut ter-update tiap deploy.
- Kunci di satu halaman lewat **Setelan → Keamanan → Sematkan aplikasi** (app pinning).
- Biarkan tercolok ke listrik. Kamera dan layar menyala terus itu berat untuk baterai.

Absen wajah menimpa status izin, bukan sebaliknya — orang yang sudah tercatat hadir tidak
bisa "diizinkan" lewat WhatsApp.

## Izin WhatsApp

Gateway meneruskan pesan masuk ke `POST /api/wa/webhook`, diverifikasi dengan tanda tangan
HMAC (middleware `wa.webhook`), bukan sesi login.

Yang dilakukan `WaController`:

- **Nama lebih menentukan daripada nomor.** Pencocokan mengabaikan tanda baca dan huruf
  besar-kecil, lalu mencari awalan nama terpanjang yang unik — `Mochamad Bayu Aji Sp`
  tetap ketemu walau di data tertulis `MOCHAMAD BAYU AJI S.P.`.
- **Nomor pengirim jadi cadangan.** Kalau nomornya terdaftar di data jamaah, pesan `izin`
  tanpa nama pun diterima, dan salah ketik nama dimaafkan sebatas nomor itu (toleransi
  Levenshtein 20% panjang nama). Nomor asing tidak pernah diberi daftar nama — itu
  kebocoran data.
- **Satu nomor bisa mewakili keluarga.** Anggota keluarga tanpa nomor sendiri ikut nomor
  kepala keluarga.
- **Izin dari nomor lain tetap diterima**, karena meminjam HP itu lumrah, tapi jamaah yang
  bersangkutan diberi tahu dan asal nomornya dicatat di Log Aktivitas.

Perintah pembantu:

```bash
php artisan wa:cek-nomor      # berapa jamaah yang benar-benar terjangkau WhatsApp
```

## API

Semua di bawah `/api`, memakai token Sanctum kecuali disebut lain.

| Method | Path | Keterangan |
|---|---|---|
| `POST` | `/auth/login` | Publik |
| `POST` | `/wa/webhook` | Publik, diverifikasi HMAC |
| `GET` | `/kegiatan-aktif` | Kegiatan yang sedang berlangsung + jadwal berikutnya + wilayah kiosk |
| `POST` | `/absensi-wajah` | Kiosk standby — kegiatan ditentukan server dari jam; di luar jam kegiatan hanya menyapa (`data.kegiatan` null) |
| `POST` | `/kegiatans/{kegiatan}/absensi-wajah` | Kiosk yang dikunci ke satu kegiatan |
| `POST` | `/jamaahs/{jamaah}/face-enroll` | Daftarkan wajah |
| `GET` | `/rekap` | Rekapitulasi kehadiran |
| `GET` | `/activity-logs` | Audit trail, khusus super admin |

Selebihnya `apiResource` biasa untuk `daerahs`, `desas`, `kelompoks`, `jamaahs`,
`kegiatans`, `users`.

## Deploy

Push ke `main` memicu GitHub Actions (`.github/workflows/deploy.yml`), yang menjalankan
`deploy/update.sh` di server: pull, `composer install`, migrasi, build Next.js, restart
PM2, health check dengan rollback otomatis, lalu purge cache nginx.

Server juga menjalankan scheduler Laravel via cron:

```
* * * * * cd /www/wwwroot/emanshurin/backend && /usr/bin/php artisan schedule:run
```

Saat ini isinya `activitylog:clean` tiap pukul 02.00 — tanpa cron itu, setelan retensi
365 hari tidak pernah berjalan.
