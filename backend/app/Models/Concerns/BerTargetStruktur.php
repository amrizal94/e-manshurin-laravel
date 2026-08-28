<?php

namespace App\Models\Concerns;

use App\Models\Daerah;
use App\Models\Desa;
use App\Models\Kelompok;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Untuk baris yang menyasar tepat satu tingkat struktur (daerah, desa, atau kelompok).
 *
 * Dipakai kegiatans dan jadwal_rutins. Sengaja satu tempat: ini aturan siapa boleh
 * melihat apa, dan salinan kedua yang ikut berubah setengah-setengah adalah lubang
 * yang tidak kelihatan sampai ada yang membuka data wilayah lain.
 */
trait BerTargetStruktur
{
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

    /** Yang menyentuh wilayah user: target di bawah scope user, atau target level atas yang mencakup user. */
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
