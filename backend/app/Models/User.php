<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'daerah_id', 'desa_id', 'kelompok_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, LogsActivity, Notifiable;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'email', 'daerah_id', 'desa_id', 'kelompok_id'])->logOnlyDirty();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function daerah(): BelongsTo
    {
        return $this->belongsTo(Daerah::class);
    }

    public function desa(): BelongsTo
    {
        return $this->belongsTo(Desa::class);
    }

    public function kelompok(): BelongsTo
    {
        return $this->belongsTo(Kelompok::class);
    }

    /**
     * Batasi query ke user dalam wilayah struktur milik $actor (super admin lihat semua).
     */
    public function scopeVisibleTo(Builder $query, self $actor): Builder
    {
        if ($actor->kelompok_id) {
            return $query->where('kelompok_id', $actor->kelompok_id);
        }
        if ($actor->desa_id) {
            return $query->where(fn ($q) => $q->where('desa_id', $actor->desa_id)
                ->orWhereHas('kelompok', fn ($k) => $k->where('desa_id', $actor->desa_id)));
        }
        if ($actor->daerah_id) {
            return $query->where(fn ($q) => $q->where('daerah_id', $actor->daerah_id)
                ->orWhereHas('desa', fn ($d) => $d->where('daerah_id', $actor->daerah_id))
                ->orWhereHas('kelompok.desa', fn ($d) => $d->where('daerah_id', $actor->daerah_id)));
        }

        return $query;
    }
}
