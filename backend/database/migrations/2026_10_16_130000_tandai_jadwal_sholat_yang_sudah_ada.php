<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lima jadwal sholat sudah ada sebelum kolom `sholat` lahir, dan isinya masih kosong.
 *
 * Tanpa penandaan ini halaman Jadwal Sholat tidak mengenalinya: layarnya tampak kosong,
 * dan sekali disimpan akan terbit lima jadwal kedua yang isinya sama — lalu semuanya
 * bentrok satu sama lain. Dicocokkan dari namanya, karena memang itu satu-satunya
 * petunjuk yang tersisa.
 *
 * Toleransinya sengaja tidak ikut diisi. Mengubahnya menyempitkan jendela absen seketika,
 * dan itu keputusan pengurus sesudah melihat jamnya, bukan keputusan migrasi.
 */
return new class extends Migration
{
    private const COCOK = [
        'subuh' => ['subuh', 'shubuh'],
        'dzuhur' => ['dzuhur', 'dhuhur', 'zuhur', 'duhur'],
        'ashar' => ['ashar', 'asar'],
        'maghrib' => ['maghrib', 'magrib'],
        'isya' => ['isya', 'isyak', 'isa'],
    ];

    public function up(): void
    {
        $jadwals = DB::table('jadwal_rutins')->whereNull('sholat')->get(['id', 'nama']);

        foreach ($jadwals as $jadwal) {
            $nama = mb_strtolower($jadwal->nama);

            if (! str_contains($nama, 'sholat') && ! str_contains($nama, 'salat')) {
                continue;
            }

            foreach (self::COCOK as $kunci => $ejaan) {
                foreach ($ejaan as $kata) {
                    if (str_contains($nama, $kata)) {
                        DB::table('jadwal_rutins')->where('id', $jadwal->id)->update(['sholat' => $kunci]);

                        continue 3;
                    }
                }
            }
        }
    }

    public function down(): void
    {
        DB::table('jadwal_rutins')->update(['sholat' => null]);
    }
};
