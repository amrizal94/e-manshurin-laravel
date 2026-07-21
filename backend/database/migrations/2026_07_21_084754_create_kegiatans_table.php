<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('kegiatans', function (Blueprint $table) {
            $table->id();
            $table->string('nama');
            $table->enum('jenis_pengajian', ['umum', 'caberawit', 'praremaja', 'remaja', 'usman']);
            // Target struktur: isi tepat satu dari tiga kolom ini.
            $table->foreignId('daerah_id')->nullable()->constrained('daerahs')->cascadeOnDelete();
            $table->foreignId('desa_id')->nullable()->constrained('desas')->cascadeOnDelete();
            $table->foreignId('kelompok_id')->nullable()->constrained('kelompoks')->cascadeOnDelete();
            $table->date('tanggal');
            $table->time('jam_mulai')->nullable();
            $table->time('jam_selesai')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->index(['tanggal', 'jenis_pengajian']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kegiatans');
    }
};
