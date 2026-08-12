<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Kategori "janda"/"duda" dan pengajian "ibu"/"bapak" ditolak database walau validasi
// aplikasi meloloskannya: enum() Laravel di Postgres itu varchar + CHECK berisi daftar
// nilai lama. Ini penambahan nilai yang ketiga, dan CHECK-nya memang tidak pernah ikut
// diperbarui (lihat migrasi 2026_07_22) — jadi sekarang dilepas saja.
//
// ponytail: daftar nilai yang sah cukup dijaga di JamaahController::rules() dan
// Kegiatan::KATEGORI_MAP. Keduanya sudah satu-satunya gerbang penulisan, dan CHECK yang
// tidak pernah ikut berubah cuma bikin nilai baru gagal di produksi tapi lolos di test.
return new class extends Migration
{
    private const CONSTRAINTS = [
        'jamaahs' => 'jamaahs_kategori_usia_check',
        'kegiatans' => 'kegiatans_jenis_pengajian_check',
    ];

    public function up(): void
    {
        // SQLite (test) tidak bisa melepas CHECK tanpa membangun ulang tabel; di sana
        // migrasi pembuat tabel sudah memakai string biasa, jadi tidak ada yang perlu dilepas.
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::CONSTRAINTS as $tabel => $constraint) {
            DB::statement("alter table {$tabel} drop constraint if exists {$constraint}");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Nilai baru dibiarkan lolos: memasang lagi daftar lama akan menolak baris
        // janda/duda dan pengajian ibu/bapak yang sudah terlanjur tersimpan.
        DB::statement("alter table jamaahs add constraint {$this->constraint('jamaahs')} check (kategori_usia in ('paud_tk', 'caberawit', 'praremaja', 'remaja', 'usman', 'menikah', 'janda', 'duda'))");
        DB::statement("alter table kegiatans add constraint {$this->constraint('kegiatans')} check (jenis_pengajian in ('umum', 'caberawit', 'praremaja', 'remaja', 'usman', 'ibu', 'bapak'))");
    }

    private function constraint(string $tabel): string
    {
        return self::CONSTRAINTS[$tabel];
    }
};
