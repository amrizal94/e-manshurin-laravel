<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable([
    'kelompok_id', 'nama_lengkap', 'nama_panggilan', 'jenis_kelamin',
    'tempat_lahir', 'tanggal_lahir', 'alamat', 'no_hp', 'kategori_usia',
    'pekerjaan', 'status_mubaligh', 'status_kk', 'kode_keluarga',
    'kepala_keluarga_id', 'aktif', 'keterangan_tidak_aktif',
])]
class Jamaah extends Model
{
    use LogsActivity;

    protected $table = 'jamaahs';

    protected $appends = ['usia'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnlyDirty()->logExcept(['updated_at']);
    }

    protected function casts(): array
    {
        return [
            'tanggal_lahir' => 'date',
            'status_mubaligh' => 'boolean',
            'aktif' => 'boolean',
        ];
    }

    protected function usia(): Attribute
    {
        return Attribute::get(fn () => $this->tanggal_lahir?->age);
    }

    /**
     * Kode keluarga selalu huruf besar dan tanpa spasi tepi: "kk-01" dan "KK-01" harus
     * jadi satu keluarga, bukan dua yang kelihatan sama di layar tapi tidak pernah ketemu.
     * Impor mengerjakan ini sendiri — insert() massal melewati mutator.
     */
    protected function kodeKeluarga(): Attribute
    {
        return Attribute::set(fn (?string $nilai) => trim((string) $nilai) === '' ? null : mb_strtoupper(trim((string) $nilai)));
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
     * Seisi keluarga satu jamaah — termasuk dirinya sendiri.
     *
     * Keluarga bisa dikenali dari dua sisi: kode keluarga (jalur impor) atau rujukan
     * kepala keluarga (jalur form). Keduanya dipakai sekaligus supaya data lama yang
     * belum punya kode tetap ketemu keluarganya tanpa perlu dimigrasi dulu.
     */
    public function scopeSekeluargaDengan(Builder $query, self $jamaah): Builder
    {
        $kepalaId = $jamaah->status_kk === 'kepala_keluarga' ? $jamaah->id : $jamaah->kepala_keluarga_id;

        return $query->where(fn (Builder $q) => $q
            ->where('id', $jamaah->id)
            ->when($jamaah->kode_keluarga, fn (Builder $w, string $kode) => $w->orWhere('kode_keluarga', $kode))
            ->when($kepalaId, fn (Builder $w, int $id) => $w->orWhere('id', $id)->orWhere('kepala_keluarga_id', $id)));
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
