<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Templat kegiatan berulang. Yang dipakai absensi tetap baris kegiatans — absensis
 * menunjuk kegiatan_id, jadi jadwal tidak bisa cuma dihitung waktu ditampilkan; ia
 * harus benar-benar menghasilkan barisnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jadwal_rutins', function (Blueprint $table) {
            $table->id();
            $table->string('nama');
            $table->string('jenis_pengajian');
            // Target struktur: isi tepat satu, aturannya sama dengan kegiatans.
            $table->foreignId('daerah_id')->nullable()->constrained('daerahs')->cascadeOnDelete();
            $table->foreignId('desa_id')->nullable()->constrained('desas')->cascadeOnDelete();
            $table->foreignId('kelompok_id')->nullable()->constrained('kelompoks')->cascadeOnDelete();
            // Hari dalam sepekan, 0 Minggu sampai 6 Sabtu — mengikuti Carbon::dayOfWeek.
            // Pondok yang mengaji tiap hari cukup mencentang ketujuhnya.
            $table->json('hari');
            $table->time('jam_mulai');
            $table->time('jam_selesai');
            $table->boolean('aktif')->default(true);
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jadwal_rutins');
    }
};
