<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// "Menikah" dihapus sebagai kategori_usia tersendiri — status nikah sekarang
// murni dari flag sudah_menikah, biar gak ada dua sumber kebenaran yang bisa
// beda (lihat App\Models\Kegiatan::pesertaQuery). Baris lama yang kategorinya
// "menikah" paling masuk akal berasal dari kelompok usia mandiri (usman)
// sebelum menikah, jadi dikembalikan ke situ + flag sudah_menikah diaktifkan.
//
// ponytail: sengaja gak mengubah CHECK constraint kolom kategori_usia di DB
// (butuh doctrine/dbal utk alter enum di Postgres, gak dipasang di proyek ini,
// dan SQLite gak bisa drop constraint tanpa rebuild tabel). Validasi aplikasi
// di JamaahController sudah jadi satu-satunya gerbang penulisan, jadi nilai
// "menikah" di constraint lama jadi dead value yang gak pernah lagi ditulis.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('jamaahs')->where('kategori_usia', 'menikah')->update([
            'kategori_usia' => 'usman',
            'sudah_menikah' => true,
        ]);
    }

    public function down(): void
    {
        // Lossy: gak bisa dibedakan mana yang aslinya "menikah" vs usman yang kebetulan menikah.
        DB::table('jamaahs')->where('kategori_usia', 'usman')->where('sudah_menikah', true)->update([
            'kategori_usia' => 'menikah',
        ]);
    }
};
