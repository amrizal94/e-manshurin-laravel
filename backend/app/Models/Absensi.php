<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['kegiatan_id', 'jamaah_id', 'status', 'keterangan', 'metode', 'waktu_absen'])]
class Absensi extends Model
{
    protected $table = 'absensis';

    protected function casts(): array
    {
        return ['waktu_absen' => 'datetime'];
    }

    public function kegiatan(): BelongsTo
    {
        return $this->belongsTo(Kegiatan::class);
    }

    public function jamaah(): BelongsTo
    {
        return $this->belongsTo(Jamaah::class);
    }
}
