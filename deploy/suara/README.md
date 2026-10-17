# Suara kiosk

Kiosk mengucapkan nama jamaah. Suara bawaan perangkat (`speechSynthesis`) tidak
selalu punya paket Bahasa Indonesia, jadi di sebagian HP nama dibaca dengan lafal
Inggris. Berkas di sini menggantikannya dengan rekaman hasil klon — satu berkas
per kalimat, dinamai sha1 kalimatnya.

Suaranya milik adik Mas Agung, dipakai dengan izin yang bersangkutan.

## Kenapa sha1 kalimatnya, bukan id jamaah

Templat ucapan bisa diedit pengurus di halaman Setelan. Kalau berkas dinamai id
jamaah, templat yang diubah membuat kiosk memutar kalimat versi lama tanpa ada
yang sadar. Dengan sha1, templat baru menunjuk berkas yang belum ada, kiosk
jatuh ke `speechSynthesis`, dan itu kedengaran — tanda bahwa berkasnya perlu
dibangun ulang.

Bonusnya: jamaah yang sapaan dan namanya kebetulan sama berbagi satu berkas
(319 jamaah -> 311 kalimat).

## Menjalankan

Butuh voicebox jalan di mesin yang punya GPU/MPS. Mac mini M1 cukup; server
produksi tidak bisa — rilis voicebox tidak menyediakan biner Linux, dan tanpa
GPU satu kalimat butuh hitungan detik sedangkan kiosk perlu suaranya seketika.

```sh
# 1. ambil daftar jamaah + templat dari produksi
scp deploy/suara/dump-jamaah.php aaPanel:/tmp/
ssh aaPanel 'cd /www/wwwroot/emanshurin/backend && php artisan tinker /tmp/dump-jamaah.php < /dev/null'
scp aaPanel:/tmp/suara-jamaah.json ./jamaah.json

# 2. generate yang belum ada (berkas yang sudah ada dilewati)
python3 bangun-suara.py jamaah.json keluaran/

# 3. periksa dan perbaiki yang rusak -- jangan dilewati, lihat di bawah
python3 periksa-suara.py keluaran/

# 4. jadikan mp3 lalu unggah
for f in keluaran/*.wav; do
  ffmpeg -v error -y -i "$f" -af loudnorm=I=-16:TP=-1.5:LRA=11 \
    -codec:a libmp3lame -b:a 64k -ac 1 "mp3/$(basename "$f" .wav).mp3"
done
tar -czf suara.tar.gz -C mp3 . && scp suara.tar.gz aaPanel:/tmp/
ssh aaPanel 'cd /www/wwwroot/emanshurin/backend/storage/app/public/suara \
  && tar -xzf /tmp/suara.tar.gz && chown -R www:www . && chmod 644 *.mp3'
```

## Kenapa langkah 3 tidak boleh dilewati

Chatterbox tidak stabil untuk teks pendek. Pada putaran pertama 311 kalimat,
**22 (7%) keluar rusak** — dan berkasnya tetap terbentuk dengan ukuran normal,
tanpa error apa pun:

```
Mas KALAM, masuk    -> "Masuk. Masuk. Masuk. Masuk."
Mas ALVO, masuk     -> "Masu. Masu. Masu."
Dek OCTAVIA, masuk  -> (nyaris kosong, whisper berhalusinasi "Terima kasih telah menonton!")
```

Jadi tiap berkas dibaca ulang whisper dan dibandingkan kemiripannya dengan teks
yang diminta. Yang dipakai kemiripan, bukan kecocokan persis: transkrip klip dua
detik ejaannya sering ngawur ("Sakha" -> "saha") padahal bunyinya benar. Ambang
0,7 memisahkan salah eja dari nama yang hilang:

```
Pak Susilo, masuk   -> "Pak Susilo masuk."     1.00
Mas Michell, masuk  -> "Mas Michelle. Masuk."  0.97
Mbak Risa, masuk    -> "Mbak Desa masuk."      0.85
Dek Sakha, masuk    -> "Saka masuk!"           0.82
(nama hilang)                                  0.55
```

Yang di bawah ambang digenerate ulang sampai tiga seed lain, diambil yang
terbaik. Semua 22 itu lolos di percobaan kedua atau ketiga. Hasil akhirnya ada
di `keluaran/laporan.json`.

## Kalimat tetap

Tiga setelan tidak memuat nama -- `suara_tak_dikenal`, `suara_sudah_absen`,
`suara_gagal` -- jadi masing-masing cukup satu berkas. Bangunnya memakai skrip
yang sama, cukup taruh kalimatnya di tempat `nama` dan biarkan templatnya
`{nama}` saja:

```json
{
 "templat": { "suara_absen": "{nama}" },
 "jamaah": [
  { "id": 1, "nama": "Maaf, wajahnya belum dikenali. Silakan ke petugas.", "jk": "L", "sapaan": "" }
 ]
}
```

## Kata yang dilafalkan keliru

Mode Melayu salah membaca sebagian kata Indonesia, dan itu **tidak acak** --
menambah seed tidak menolong. Yang sudah ketemu:

```
"coba"   -> "khoba" / "koba" / "khobar"   (sama di tiga seed berbeda)
angka    -> "pukul 18.30" jadi bunyi kacau; tulis "delapan belas tiga puluh"
```

Jadi kalau pemeriksa menandai satu kalimat dan generate ulang tetap jelek,
curigai katanya, bukan modelnya. `suara_gagal` bawaannya "Maaf, belum terbaca.
Silakan ulangi." justru karena versi dengan "coba" tidak pernah benar.

## Jamaah baru

Tidak perlu membangun ulang semuanya — langkah 2 melewati berkas yang sudah ada,
jadi 13 jamaah baru berarti 13 berkas. Selama berkasnya belum ada, jamaah itu
tidak bisu: kiosk memakai `speechSynthesis` seperti sebelum ada berkas sama
sekali.

## Memulihkan profil suaranya

Profil voicebox-nya tidak dicadangkan di sini — isinya rekaman suara orang.
Kalau hilang, buat ulang dari dua potongan 30 detik rekaman seminar yang
dipegang AMR: profil baru bahasa `ms`, engine `chatterbox`, dua sampel itu
dipasang dengan transkripnya. Lima menit, dan berkas yang sudah jadi tidak
terpengaruh.
