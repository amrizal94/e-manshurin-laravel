<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable(['desa_id', 'nama'])]
class Kelompok extends Model
{
    use LogsActivity;

    protected $table = 'kelompoks';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnlyDirty()->logExcept(['updated_at']);
    }

    public function desa(): BelongsTo
    {
        return $this->belongsTo(Desa::class);
    }

    /** Batasi query ke wilayah struktur milik user (super admin lihat semua). */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->kelompok_id) {
            return $query->where('id', $user->kelompok_id);
        }
        if ($user->desa_id) {
            return $query->where('desa_id', $user->desa_id);
        }
        if ($user->daerah_id) {
            return $query->whereHas('desa', fn ($q) => $q->where('daerah_id', $user->daerah_id));
        }

        return $query;
    }
}
