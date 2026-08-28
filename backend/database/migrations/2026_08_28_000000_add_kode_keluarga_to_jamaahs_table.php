<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keluarga sudah bisa dinyatakan lewat kepala_keluarga_id, tapi id belum ada waktu file
 * impor ditulis — tidak ada cara menyebut "orang-orang ini serumah" di dalam Excel.
 * Kode keluarga adalah penyebutnya: kolom teks bebas yang disamakan untuk satu rumah,
 * lalu diterjemahkan jadi kepala_keluarga_id sesudah barisnya masuk.
 *
 * Sengaja bukan nomor KK 16 digit: yang dibutuhkan cuma pengelompokan, dan menyimpan
 * nomor identitas kependudukan 7000 orang menaikkan taruhannya tanpa menambah guna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jamaahs', function (Blueprint $table) {
            $table->string('kode_keluarga', 50)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('jamaahs', function (Blueprint $table) {
            $table->dropIndex(['kode_keluarga']);
            $table->dropColumn('kode_keluarga');
        });
    }
};
