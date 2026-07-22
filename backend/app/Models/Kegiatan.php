<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable([
    'nama', 'jenis_pengajian', 'daerah_id', 'desa_id', 'kelompok_id',
    'tanggal', 'jam_mulai', 'jam_selesai', 'created_by',
])]
class Kegiatan extends Model
{
    use LogsActivity;

    protected $table = 'kegiatans';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnlyDirty()->logExcept(['updated_at']);
    }

    /**
     * Jenis pengajian -> kategori usia jamaah yang boleh absen.
     * "Menikah" bukan kategori_usia tersendiri — status nikah murni dari flag
     * sudah_menikah, supaya gak ada dua sumber kebenaran yang bisa beda (lihat pesertaQuery).
     * Usman eksplisit "belum menikah" per definisi jenis pengajian ini.
     */
    public const KATEGORI_MAP = [
        'umum' => ['praremaja', 'remaja', 'usman'],
        'caberawit' => ['paud_tk', 'caberawit'],
        'praremaja' => ['praremaja'],
        'remaja' => ['remaja'],
        'usman' => ['usman'],
    ];

    protected function casts(): array
    {
        return ['tanggal' => 'date'];
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function absensis(): HasMany
    {
        return $this->hasMany(Absensi::class);
    }

    /** Jamaah aktif yang berhak absen di kegiatan ini (sesuai target struktur + kategori usia). */
    public function pesertaQuery(): Builder
    {
        $query = Jamaah::where('aktif', true)->where(function (Builder $q) {
            $q->whereIn('kategori_usia', self::KATEGORI_MAP[$this->jenis_pengajian]);

            // Usman berarti "belum menikah" — begitu menikah, keluar dari usman
            // tapi tetap ikut pengajian Umum (bukan lewat kategori_usia baru, cukup flag).
            if ($this->jenis_pengajian === 'usman') {
                $q->where('sudah_menikah', false);
            } elseif ($this->jenis_pengajian === 'umum') {
                $q->orWhere('sudah_menikah', true);
            }
        });

        if ($this->kelompok_id) {
            return $query->where('kelompok_id', $this->kelompok_id);
        }
        if ($this->desa_id) {
            return $query->whereHas('kelompok', fn ($q) => $q->where('desa_id', $this->desa_id));
        }

        return $query->whereHas('kelompok.desa', fn ($q) => $q->where('daerah_id', $this->daerah_id));
    }

    /** Kegiatan yang menyentuh wilayah user: target di bawah scope user, atau target level atas yang mencakup user. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->kelompok_id) {
            $kelompok = $user->kelompok()->with('desa')->first();

            return $query->where(fn ($q) => $q
                ->where('kelompok_id', $kelompok->id)
                ->orWhere('desa_id', $kelompok->desa_id)
                ->orWhere('daerah_id', $kelompok->desa->daerah_id));
        }

        if ($user->desa_id) {
            $desa = $user->desa;

            return $query->where(fn ($q) => $q
                ->whereIn('kelompok_id', Kelompok::where('desa_id', $desa->id)->select('id'))
                ->orWhere('desa_id', $desa->id)
                ->orWhere('daerah_id', $desa->daerah_id));
        }

        if ($user->daerah_id) {
            $desaIds = Desa::where('daerah_id', $user->daerah_id)->select('id');

            return $query->where(fn ($q) => $q
                ->whereIn('kelompok_id', Kelompok::whereIn('desa_id', $desaIds)->select('id'))
                ->orWhereIn('desa_id', $desaIds)
                ->orWhere('daerah_id', $user->daerah_id));
        }

        return $query;
    }
}
