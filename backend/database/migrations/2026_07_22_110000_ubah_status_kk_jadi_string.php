<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// status_kk dari enum() (CHECK constraint di DB) jadi string biasa — validasi
// daftar nilai yang diizinkan cukup di JamaahController (satu-satunya jalur
// tulis), jadi nambah pilihan baru (cucu, menantu, mertua, orang_tua) gak perlu
// bongkar CHECK constraint di Postgres/SQLite tiap kali daftarnya berubah.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jamaahs', function (Blueprint $table) {
            $table->string('status_kk', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('jamaahs', function (Blueprint $table) {
            $table->enum('status_kk', ['kepala_keluarga', 'suami', 'istri', 'anak'])->nullable()->change();
        });
    }
};
