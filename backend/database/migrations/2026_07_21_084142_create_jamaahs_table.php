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
        Schema::create('jamaahs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kelompok_id')->constrained('kelompoks')->cascadeOnDelete();
            $table->string('nama_lengkap');
            $table->string('nama_panggilan')->nullable();
            $table->enum('jenis_kelamin', ['L', 'P']);
            $table->string('tempat_lahir')->nullable();
            $table->date('tanggal_lahir')->nullable();
            $table->text('alamat')->nullable();
            $table->string('no_hp')->nullable();
            $table->enum('kategori_usia', ['paud_tk', 'caberawit', 'praremaja', 'remaja', 'usman', 'menikah']);
            $table->string('pekerjaan')->nullable();
            $table->boolean('status_mubaligh')->default(false);
            $table->boolean('sudah_menikah')->default(false);
            $table->enum('status_kk', ['kepala_keluarga', 'suami', 'istri', 'anak'])->nullable();
            $table->foreignId('kepala_keluarga_id')->nullable()->constrained('jamaahs')->nullOnDelete();
            $table->boolean('aktif')->default(true);
            $table->text('keterangan_tidak_aktif')->nullable();
            $table->timestamps();
            $table->index(['kelompok_id', 'kategori_usia']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jamaahs');
    }
};
