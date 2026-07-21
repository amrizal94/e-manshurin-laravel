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
}
