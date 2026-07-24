<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable(['daerah_id', 'nama'])]
class Desa extends Model
{
    use LogsActivity;

    protected $table = 'desas';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnlyDirty()->logExcept(['updated_at']);
    }

    public function daerah(): BelongsTo
    {
        return $this->belongsTo(Daerah::class);
    }

    public function kelompoks(): HasMany
    {
        return $this->hasMany(Kelompok::class);
    }

    /** Batasi query ke wilayah struktur milik user (super admin lihat semua). */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->kelompok_id) {
            return $query->whereHas('kelompoks', fn ($q) => $q->where('id', $user->kelompok_id));
        }
        if ($user->desa_id) {
            return $query->where('id', $user->desa_id);
        }
        if ($user->daerah_id) {
            return $query->where('daerah_id', $user->daerah_id);
        }

        return $query;
    }
}
