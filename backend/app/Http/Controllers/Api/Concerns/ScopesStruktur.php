<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Desa;
use App\Models\Kelompok;
use App\Models\User;

trait ScopesStruktur
{
    /**
     * Boleh mengubah atau menghapus barang ini?
     *
     * Terlihat tidak sama dengan milik sendiri. Pengurus kelompok memang harus melihat
     * jadwal dan kegiatan tingkat desa dan daerah — jamaahnya ikut hadir di situ — tapi
     * yang boleh mengubahnya hanya pengurus wilayah yang punya. Tanpa pemeriksaan ini,
     * admin kelompok bisa mengambil alih jadwal sholat daerah lalu mengarahkannya ke
     * kelompoknya sendiri, dan jadwal itu lenyap dari kelompok lain se-daerah.
     */
    private function assertMilikWilayahSendiri(User $actor, object $barang): void
    {
        abort_unless($this->targetWithinScope($actor, [
            'daerah_id' => $barang->daerah_id,
            'desa_id' => $barang->desa_id,
            'kelompok_id' => $barang->kelompok_id,
        ]), 403, 'Ini milik wilayah di atas Anda, jadi hanya pengurusnya yang bisa mengubah.');
    }

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
