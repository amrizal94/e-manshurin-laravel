<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kegiatans', function (Blueprint $table) {
            // nullOnDelete, bukan cascade: menghapus templat jadwal tidak boleh ikut
            // menghapus kegiatan yang absensinya sudah tercatat. Yang tertinggal jadi
            // kegiatan biasa, persis seperti kalau dibuat manual.
            $table->foreignId('jadwal_rutin_id')->nullable()->constrained('jadwal_rutins')->nullOnDelete();

            // Libur ditandai, bukan dihapus. Baris yang dihapus akan dibuat lagi oleh
            // penghasil jadwal berikutnya; baris yang ditandai tetap ada dan dilewati.
            $table->boolean('libur')->default(false);
            $table->text('keterangan_libur')->nullable();

            // Satu jadwal cuma boleh punya satu kegiatan per tanggal. Ini yang bikin
            // perintah generate aman dijalankan berulang kali sehari.
            $table->unique(['jadwal_rutin_id', 'tanggal']);
        });
    }

    public function down(): void
    {
        Schema::table('kegiatans', function (Blueprint $table) {
            $table->dropUnique(['jadwal_rutin_id', 'tanggal']);
            $table->dropForeign(['jadwal_rutin_id']);
            $table->dropColumn(['jadwal_rutin_id', 'libur', 'keterangan_libur']);
        });
    }
};
