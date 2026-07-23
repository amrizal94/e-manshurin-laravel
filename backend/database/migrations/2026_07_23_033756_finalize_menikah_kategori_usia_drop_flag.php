<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Client revisi: "Menikah" balik jadi kategori_usia sendiri (bukan flag terpisah lagi),
// biar cukup satu pilihan di form. Kolom sudah_menikah pun dihapus.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('jamaahs')->where('kategori_usia', 'usman')->where('sudah_menikah', true)->update([
            'kategori_usia' => 'menikah',
        ]);

        Schema::table('jamaahs', function (Blueprint $table) {
            $table->dropColumn('sudah_menikah');
        });
    }

    public function down(): void
    {
        Schema::table('jamaahs', function (Blueprint $table) {
            $table->boolean('sudah_menikah')->default(false)->after('status_mubaligh');
        });

        DB::table('jamaahs')->where('kategori_usia', 'menikah')->update([
            'kategori_usia' => 'usman',
            'sudah_menikah' => true,
        ]);
    }
};
