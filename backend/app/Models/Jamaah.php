<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'kelompok_id', 'nama_lengkap', 'nama_panggilan', 'jenis_kelamin',
    'tempat_lahir', 'tanggal_lahir', 'alamat', 'no_hp', 'kategori_usia',
    'pekerjaan', 'status_mubaligh', 'sudah_menikah', 'status_kk',
    'kepala_keluarga_id', 'aktif', 'keterangan_tidak_aktif',
])]
class Jamaah extends Model
{
    protected $table = 'jamaahs';

    protected $appends = ['usia'];

    protected function casts(): array
    {
        return [
            'tanggal_lahir' => 'date',
            'status_mubaligh' => 'boolean',
            'sudah_menikah' => 'boolean',
            'aktif' => 'boolean',
        ];
    }

    protected function usia(): Attribute
    {
        return Attribute::get(fn () => $this->tanggal_lahir?->age);
    }

    public function kelompok(): BelongsTo
    {
        return $this->belongsTo(Kelompok::class);
    }

    public function kepalaKeluarga(): BelongsTo
    {
        return $this->belongsTo(self::class, 'kepala_keluarga_id');
    }

    public function anggotaKeluarga(): HasMany
    {
        return $this->hasMany(self::class, 'kepala_keluarga_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(JamaahPhoto::class);
    }

    /**
     * Batasi query ke wilayah struktur milik user (super admin lihat semua).
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->kelompok_id) {
            return $query->where('kelompok_id', $user->kelompok_id);
        }
        if ($user->desa_id) {
            return $query->whereHas('kelompok', fn ($q) => $q->where('desa_id', $user->desa_id));
        }
        if ($user->daerah_id) {
            return $query->whereHas('kelompok.desa', fn ($q) => $q->where('daerah_id', $user->daerah_id));
        }

        return $query;
    }
}
