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

    public const DEFAULT_WA_REPLY_TEMPLATE = 'Terima kasih {nama}, izin Anda pada kegiatan "{kegiatan}" tercatat: {keterangan}';

    /**
     * Key yang boleh dibaca dan diubah lewat API, beserta isi bawaannya. Menambah
     * pengaturan baru cukup menambah satu baris di sini.
     */
    public const BAWAAN = [
        self::WA_REPLY_TEMPLATE => self::DEFAULT_WA_REPLY_TEMPLATE,
        self::SUARA_ABSEN => '{doa}, {sapaan} {nama}, sudah absen',
        self::SUARA_IDLE => 'Halo {sapaan} {nama}, saat ini belum ada kegiatan ya. Pengajian berikutnya pukul {jam_berikutnya}.',
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
