<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// activitylog.delete_records_older_than_days cuma berlaku kalau perintah ini benar-benar jalan.
//
// --force wajib: perintah ini menghapus baris, jadi Laravel menuntut konfirmasi di
// produksi. Penjadwal tidak punya siapa-siapa untuk menjawab, jadi tiap malam perintahnya
// batal sendiri dengan exit code 1 — gagal tanpa ada yang melihat, dan tabelnya terus
// membesar. Ketahuan dari laravel.log, bukan dari keluhan siapa pun.
Schedule::command('activitylog:clean', ['--force'])->dailyAt('02:00');

// Tanpa ini jadwal rutin habis di ujung horizonnya dan pengajian mendadak tidak ada
// kegiatannya — kiosk diam, tidak ada yang bisa absen.
Schedule::command('kegiatan:generate')->dailyAt('01:00');

// Device WA bisa logout diam-diam. Tanpa cek berkala, matinya baru ketahuan waktu ada
// jamaah mengeluh izinnya tidak dibalas.
Schedule::command('wa:healthcheck')->hourly()->withoutOverlapping();
