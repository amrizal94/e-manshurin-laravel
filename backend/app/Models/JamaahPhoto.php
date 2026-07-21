<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['jamaah_id', 'path'])]
class JamaahPhoto extends Model
{
    public function jamaah(): BelongsTo
    {
        return $this->belongsTo(Jamaah::class);
    }
}
