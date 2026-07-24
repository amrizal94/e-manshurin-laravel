<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Desa;
use App\Models\Kelompok;
use App\Models\User;

trait ScopesStruktur
{
    /** Cek kombinasi daerah_id/desa_id/kelompok_id di $data ada di dalam wilayah actor (super admin selalu lolos). */
    private function targetWithinScope(User $actor, array $data): bool
    {
        if (! $actor->daerah_id && ! $actor->desa_id && ! $actor->kelompok_id) {
            return true;
        }

        return match (true) {
            (bool) $actor->kelompok_id => ($data['kelompok_id'] ?? null) == $actor->kelompok_id,
            (bool) $actor->desa_id => ($data['desa_id'] ?? null) == $actor->desa_id
                || (($data['kelompok_id'] ?? null) && Kelompok::where('id', $data['kelompok_id'])->where('desa_id', $actor->desa_id)->exists()),
            default => ($data['daerah_id'] ?? null) == $actor->daerah_id
                || (($data['desa_id'] ?? null) && Desa::where('id', $data['desa_id'])->where('daerah_id', $actor->daerah_id)->exists())
                || (($data['kelompok_id'] ?? null) && Kelompok::where('id', $data['kelompok_id'])->whereHas('desa', fn ($q) => $q->where('daerah_id', $actor->daerah_id))->exists()),
        };
    }
}
