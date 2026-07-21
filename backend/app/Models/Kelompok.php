<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['desa_id', 'nama'])]
class Kelompok extends Model
{
    protected $table = 'kelompoks';

    public function desa(): BelongsTo
    {
        return $this->belongsTo(Desa::class);
    }
}
