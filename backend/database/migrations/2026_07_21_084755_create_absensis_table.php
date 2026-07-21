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
        Schema::create('absensis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kegiatan_id')->constrained('kegiatans')->cascadeOnDelete();
            $table->foreignId('jamaah_id')->constrained('jamaahs')->cascadeOnDelete();
            $table->enum('status', ['hadir', 'izin', 'alpha']);
            $table->text('keterangan')->nullable();
            $table->enum('metode', ['face', 'manual', 'wa'])->default('manual');
            $table->timestamp('waktu_absen')->nullable();
            $table->timestamps();
            $table->unique(['kegiatan_id', 'jamaah_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('absensis');
    }
};
