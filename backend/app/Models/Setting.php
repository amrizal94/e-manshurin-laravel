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

    public const DEFAULT_WA_REPLY_TEMPLATE = 'Terima kasih {nama}, izin Anda pada kegiatan "{kegiatan}" tercatat: {keterangan}';

    public static function get(string $key, ?string $default = null): ?string
    {
        return static::where('key', $key)->value('value') ?? $default;
    }

    public static function set(string $key, string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
