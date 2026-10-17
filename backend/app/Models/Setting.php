<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable(['key', 'value'])]
class Setting extends Model
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnlyDirty()->logExcept(['updated_at']);
    }

    public const WA_REPLY_TEMPLATE = 'wa_reply_template';

    public const SUARA_ABSEN = 'suara_absen';

    public const SUARA_IDLE = 'suara_idle';

    public const SUARA_TAK_DIKENAL = 'suara_tak_dikenal';

    public const SUARA_SUDAH_ABSEN = 'suara_sudah_absen';

    public const SUARA_GAGAL = 'suara_gagal';

    public const KOORDINAT = 'koordinat';

    public const DEFAULT_WA_REPLY_TEMPLATE = 'Terima kasih {nama}, izin Anda pada kegiatan "{kegiatan}" tercatat: {keterangan}';

    /**
     * Key yang boleh dibaca dan diubah lewat API, beserta isi bawaannya. Menambah
     * pengaturan baru cukup menambah satu baris di sini.
     */
    public const BAWAAN = [
        self::WA_REPLY_TEMPLATE => self::DEFAULT_WA_REPLY_TEMPLATE,
        self::SUARA_ABSEN => '{doa}, {sapaan} {nama}, sudah absen',
        self::SUARA_IDLE => 'Halo {sapaan} {nama}, saat ini belum ada kegiatan ya. Pengajian berikutnya pukul {jam_berikutnya}.',
        // Tanpa nama, sengaja: pengenalan wajah kadang gagal, dan menyebut orangnya
        // tidak terdaftar di depan balai padahal sistemnya yang keliru itu lebih buruk
        // daripada diam. Kalimatnya menyebut batas sistem, bukan status orangnya.
        self::SUARA_TAK_DIKENAL => 'Maaf, wajahnya belum dikenali. Silakan ke petugas.',
        // Tanpa nama supaya cukup satu berkas rekaman, bukan satu per jamaah.
        // Boleh diberi {sapaan} {nama}, tapi kalimat itu lalu dibaca suara bawaan
        // perangkat sampai berkasnya dibangun -- lihat deploy/suara/README.md.
        self::SUARA_SUDAH_ABSEN => 'Sudah absen tadi ya.',
        // "coba" dilafalkan keliru oleh mesin suaranya (keluar "khoba") di ketiga
        // seed yang dicoba, jadi katanya dihindari -- bukan masalah acak.
        self::SUARA_GAGAL => 'Maaf, belum terbaca. Silakan ulangi.',
        // Dipakai kiosk menghitung waktu sholat di perangkatnya sendiri. Bawaannya Kediri;
        // selisih beberapa kilometer tidak mengubah menitnya, jadi tidak perlu presisi.
        self::KOORDINAT => '-7.8166,112.0114',
    ];

    public static function get(string $key, ?string $default = null): ?string
    {
        return static::where('key', $key)->value('value') ?? $default;
    }

    /** Seluruh pengaturan sekaligus: yang belum pernah disimpan diisi bawaannya. */
    public static function semua(): array
    {
        $tersimpan = static::pluck('value', 'key')->all();

        return collect(self::BAWAAN)->map(fn ($bawaan, $key) => $tersimpan[$key] ?? $bawaan)->all();
    }

    public static function set(string $key, string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
