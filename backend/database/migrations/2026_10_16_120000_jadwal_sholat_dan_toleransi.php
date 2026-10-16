<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dua hal sekaligus, karena keduanya lahir dari persoalan yang sama.
 *
 * 1. `sholat` menandai jadwal mana yang mewakili sholat berjamaah. Halaman Jadwal Sholat
 *    menyuntingnya; di baliknya tetap jadwal rutin biasa, supaya tidak ada dua tempat yang
 *    sama-sama mengaku menentukan jam sholat.
 *
 * 2. `toleransi_menit` jadi milik tiap jadwal, bukan satu angka untuk semua. Toleransi 30
 *    menit dibuat untuk pengajian — jamaah datang lebih awal, ada yang absen susulan.
 *    Untuk sholat 30 menit yang jamaahnya sudah berdiri di masjid, angka itu membuat satu
 *    sholat memakan jendela 90 menit, dan Ashar selalu bertabrakan dengan acara
 *    sebelumnya berapa pun jamnya digeser.
 *
 * patokan_sholat/offset_menit/durasi_menit dicabut: jamnya ditentukan pengurus, dan
 * hitungan otomatis cukup jadi pembanding di layar. Dua mekanisme untuk tujuan yang sama
 * hanya membingungkan orang yang membukanya enam bulan lagi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jadwal_rutins', function (Blueprint $table) {
            $table->dropColumn(['patokan_sholat', 'offset_menit', 'durasi_menit']);
            $table->string('sholat')->nullable();
            $table->smallInteger('toleransi_menit')->nullable();
        });

        // Disalin ke kegiatan saat terbit, seperti jamnya: kiosk dan pemeriksa bentrok
        // membaca kegiatannya saja dan tidak perlu tahu jadwal mana yang melahirkannya.
        Schema::table('kegiatans', function (Blueprint $table) {
            $table->smallInteger('toleransi_menit')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('jadwal_rutins', function (Blueprint $table) {
            $table->dropColumn(['sholat', 'toleransi_menit']);
            $table->string('patokan_sholat')->nullable();
            $table->smallInteger('offset_menit')->nullable();
            $table->smallInteger('durasi_menit')->nullable();
        });

        Schema::table('kegiatans', function (Blueprint $table) {
            $table->dropColumn('toleransi_menit');
        });
    }
};
