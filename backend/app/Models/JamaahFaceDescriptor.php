<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['jamaah_id', 'jamaah_photo_id', 'descriptor', 'confidence'])]
#[Hidden(['descriptor'])]
class JamaahFaceDescriptor extends Model
{
    protected function casts(): array
    {
        return ['descriptor' => 'encrypted:array'];
    }

    public function jamaah(): BelongsTo
    {
        return $this->belongsTo(Jamaah::class);
    }

    /**
     * Cosine similarity — embedding sudah L2-normalized, jadi cukup dot product.
     *
     * Di model, bukan di controller: pencocokan kiosk, penjaga enroll, dan pemeriksa
     * foto janggal harus memakai rumus yang sama persis, kalau tidak ambang yang
     * tertulis di config berarti tiga hal berbeda.
     *
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    public static function similarity(array $a, array $b): float
    {
        $dot = 0.0;
        foreach ($a as $i => $v) {
            $dot += $v * ($b[$i] ?? 0.0);
        }

        return $dot;
    }
}
