<?php

namespace App\Support;

/**
 * Tulisan yang dibaca manusia untuk kode-kode yang disimpan di database.
 *
 * Salinan kedua dari web/lib/labels.ts, dan memang disengaja: berkas Excel dirakit di
 * server, jadi server harus tahu "caberawit" itu dibaca "Caberawit (SD)". Isinya cuma
 * yang dipakai berkas unduhan — selebihnya tetap urusan halaman webnya.
 */
class Label
{
    public const KATEGORI_USIA = [
        'paud_tk' => 'PAUD & TK',
        'caberawit' => 'Caberawit (SD)',
        'praremaja' => 'Praremaja (SMP)',
        'remaja' => 'Remaja (SMA)',
        'usman' => 'Usia Mandiri',
        'menikah' => 'Menikah',
        'janda' => 'Janda',
        'duda' => 'Duda',
    ];

    public const JENIS_PENGAJIAN = [
        'umum' => 'Umum',
        'caberawit' => 'Caberawit',
        'praremaja' => 'Praremaja',
        'remaja' => 'Remaja',
        'usman' => 'Usman',
        'ibu' => 'Ibu-ibu',
        'bapak' => 'Bapak-bapak',
        'pengurus_4s' => 'Pengurus 4S',
    ];

    public static function kategoriUsia(string $kode): string
    {
        return self::KATEGORI_USIA[$kode] ?? $kode;
    }

    public static function jenisPengajian(string $kode): string
    {
        return self::JENIS_PENGAJIAN[$kode] ?? $kode;
    }
}
