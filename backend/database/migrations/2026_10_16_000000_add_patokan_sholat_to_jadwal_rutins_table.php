<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengajian pondok jamnya mengikuti waktu sholat, dan waktu sholat bergeser tiap hari.
 * Jadwal rutin yang jamnya tetap tidak bisa menyatakan "15 menit setelah Maghrib".
 *
 * jam_mulai dan jam_selesai dibiarkan apa adanya dan tetap wajib: yang berpatokan sholat
 * pun menyimpan jam terakhir yang dihitung di sana, jadi daftar jadwal tetap bisa
 * menampilkan perkiraan jamnya tanpa menghitung ulang, dan jadwal lama tidak perlu
 * disentuh sama sekali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jadwal_rutins', function (Blueprint $table) {
            $table->string('patokan_sholat')->nullable();
            // Boleh minus: "10 menit sebelum Subuh" sama sahnya dengan "15 menit sesudah".
            $table->smallInteger('offset_menit')->nullable();
            $table->smallInteger('durasi_menit')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('jadwal_rutins', function (Blueprint $table) {
            $table->dropColumn(['patokan_sholat', 'offset_menit', 'durasi_menit']);
        });
    }
};
