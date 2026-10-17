# Suara kiosk

Kiosk mengucapkan nama jamaah. Suara bawaan perangkat (`speechSynthesis`) tidak
selalu punya paket Bahasa Indonesia, jadi di sebagian HP nama dibaca dengan lafal
Inggris. Berkas di sini menggantikannya dengan rekaman suara sungguhan -- satu
berkas per kalimat, dinamai sha1 kalimatnya.

Suaranya milik adik Mas Agung, dipakai dengan izin yang bersangkutan.

## Dua tahap, bukan satu

```
teks  ->  Piper (id_ID)  ->  Seed-VC  ->  berkas
          lafal Indonesia   warna suara
```

Tidak ada mesin kloning sumber terbuka yang bahasanya Indonesia. Yang pertama
dipakai, Chatterbox, dijalankan di mode Melayu karena `id` tidak ada di daftar
23 bahasanya. Akibatnya terukur: `"coba"` terbaca `"khoba"` di ketiga seed yang
dicoba, `"pukul 18.30"` rusak total sehingga jam harus ditulis huruf, dan **22
dari 311 kalimat (7%) keluar ngawur secara acak** -- `"Mas KALAM, masuk"` jadi
`"Masuk. Masuk. Masuk."` dengan ukuran berkas normal dan tanpa error.

Memisah pekerjaannya menyelesaikan ketiganya:

| | Chatterbox (lama) | Piper + Seed-VC |
|---|---|---|
| kemiripan transkrip, 311 kalimat | 0,897 | **0,967** |
| di bawah ambang | 22 | **2** |
| kemiripan penutur, kalimat identik | 0,770 | **0,807** |
| `"coba"` | salah | benar |
| `"pukul 18.30"` | rusak | benar |
| gagal acak | 7% | **0% -- deterministik** |

Pembanding kemiripan penutur: rekaman asli orangnya yang lain **0,947**, suara
orang asing **0,64**. Jadi 0,807 itu di antara keduanya, condong ke dia.

Catatan jujur: pada kalimat pendek keunggulan kemiripannya tipis, dan dua dari
lima kalimat uji malah sedikit kalah. Yang telak unggul pada kalimat panjang
(0,925 vs 0,797), karena pengukur suara butuh bahan cukup. Keunggulan besarnya
ada di lafal dan di hilangnya keacakan.

## Kenapa sha1 kalimatnya, bukan id jamaah

Templat ucapan bisa diedit pengurus di halaman Setelan. Kalau berkas dinamai id
jamaah, templat yang diubah membuat kiosk memutar kalimat versi lama tanpa ada
yang sadar. Dengan sha1, templat baru menunjuk berkas yang belum ada, kiosk
jatuh ke `speechSynthesis`, dan itu kedengaran.

Bonus yang baru terasa saat mesinnya diganti: **seluruh pembangkitnya bisa
ditukar tanpa menyentuh aplikasi.** Kiosk tidak tahu dan tidak perlu tahu.

Bonus kedua: jamaah yang sapaan dan namanya kebetulan sama berbagi satu berkas
(319 jamaah -> 311 kalimat).

## ejaan.json

sha1 dihitung dari kalimat **asli** dari basis data; yang dikirim ke Piper boleh
berbeda. Dua penyesuaian dipakai:

- Nama KAPITAL dibaca espeak seperti singkatan, jadi dijadikan Title Case.
  `"Dek AKMAL MBAPPE"` 0,83 -> `"Dek Akmal Mbappe"` **1,00**. Begitu juga
  `RIDWAN` dan `ZAR'IN`, dua-duanya jadi 1,00.
- Sebagian nama kalah oleh aturan ejaan espeak berapa pun kapitalnya. Itu isi
  `ejaan.json`: `"IRSYAD"` dibaca `"Dick is shot"`, dengan ejaan `"Ir syad"`
  keluar tepat `"Dek Irsyad"`.

Karena Piper deterministik, satu entri berlaku selamanya -- bukan diundi ulang
sampai beruntung seperti mesin yang lama.

## Menjalankan

Butuh Piper dan Seed-VC di Mac mini (M1). Server produksi tidak bisa: tanpa GPU
konversinya berjam-jam, dan kiosk perlu suaranya seketika.

```sh
# 1. ambil daftar jamaah + templat dari produksi
ssh aaPanel dump > jamaah.json          # lewat kunci terbatas, lihat agen.sh

# 2. bangun yang belum ada (berkas yang sudah ada dilewati)
~/seed-vc/.venv/bin/python bangun-suara.py jamaah.json keluaran/

# 3. periksa
~/seed-vc/.venv/bin/python periksa-suara.py keluaran/

# 4. jadikan mp3 lalu unggah -- atau biarkan malam.sh yang mengerjakannya
```

Seed-VC lambat: RTF 9-15 di M1, sekitar 30 detik per kalimat. Bangun ulang 311
berkas sekitar 2,5 jam. Tambahan bulanan 13 jamaah sekitar 7 menit, dan itu
jalan jam 2 pagi tanpa ditunggu.

## Otomatis tiap malam

```
Mac mini  --(ssh, kunci dibatasi)-->  server
  malam.sh                              agen.sh
    dump    minta daftar jamaah + templat
    punya   minta nama berkas yang sudah ada
    terima  kirim mp3 yang baru lewat stdin
```

Arahnya dari rumah ke server, bukan sebaliknya. Server terbuka ke internet;
memberinya kunci masuk ke mesin rumah demi menyalin mp3 tidak sebanding. Kunci
Mac mini di sisi server pun tidak bisa apa-apa selain tiga perintah itu --
`authorized_keys`-nya memakai `restrict,command="..."` ke `agen.sh`, dan
`agen.sh` membuang berkas non-mp3 dari kiriman sebelum memindahkannya ke
direktori yang dilayani nginx.

Alamat yang dipakai alamat publik, bukan tailnet: server memakai Tailscale SSH,
yang menyadap SSH di alamat `100.x` dan menuntut persetujuan lewat browser.
Sambungannya menggantung menunggu seseorang mengeklik tautan -- mustahil untuk
pekerjaan tanpa penunggu, dan gejalanya cuma "menggantung", bukan pesan galat.

Mac mini mati atau tidur? tidak ada yang rusak: berkasnya belum muncul, jamaah
itu disapa suara bawaan, malam berikutnya dicoba lagi. Log di
`~/suara-v2/malam.log`.

## Jamaah baru

Tidak perlu membangun ulang semuanya -- langkah 2 melewati berkas yang sudah
ada, jadi 13 jamaah baru berarti 13 berkas. Selama berkasnya belum ada, jamaah
itu tidak bisu: kiosk memakai `speechSynthesis` seperti sebelum ada berkas sama
sekali.

## Memulihkan rujukan suaranya

Rekaman rujukannya tidak dicadangkan di sini -- isinya suara orang. Yang
dipakai `~/whisper/ref-a.wav` di Mac mini, hasil normalisasi potongan 30 detik
rekaman seminar yang dipegang AMR. Kalau hilang, buat ulang dari potongan itu:
mono 24 kHz, `highpass=f=70,loudnorm=I=-18:TP=-2:LRA=9`. Berkas yang sudah jadi
tidak terpengaruh.
