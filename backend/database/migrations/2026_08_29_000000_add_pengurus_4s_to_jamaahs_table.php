<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengurus 4S dijadwalkan pengajian sendiri, jadi keanggotaannya harus bisa disaring —
 * bukan cuma dicatat. Bendera, bukan tabel peran: satu penanda ya/tidak per jamaah,
 * sama seperti status_mubaligh, dan itu yang dipakai Kegiatan::FLAG_MAP memilih peserta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jamaahs', function (Blueprint $table) {
            $table->boolean('pengurus_4s')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('jamaahs', function (Blueprint $table) {
            $table->dropColumn('pengurus_4s');
        });
    }
};
