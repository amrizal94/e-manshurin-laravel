<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable(['nama'])]
class Daerah extends Model
{
    use LogsActivity;

    protected $table = 'daerahs';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnlyDirty()->logExcept(['updated_at']);
    }

    public function desas(): HasMany
    {
        return $this->hasMany(Desa::class);
    }

    /** Batasi query ke wilayah struktur milik user (super admin lihat semua). */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->kelompok_id) {
            return $query->whereHas('desas.kelompoks', fn ($q) => $q->where('id', $user->kelompok_id));
        }
        if ($user->desa_id) {
            return $query->whereHas('desas', fn ($q) => $q->where('id', $user->desa_id));
        }
        if ($user->daerah_id) {
            return $query->where('id', $user->daerah_id);
        }

        return $query;
    }
}
