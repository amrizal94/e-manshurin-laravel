<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['daerah_id', 'nama'])]
class Desa extends Model
{
    protected $table = 'desas';

    public function daerah(): BelongsTo
    {
        return $this->belongsTo(Daerah::class);
    }

    public function kelompoks(): HasMany
    {
        return $this->hasMany(Kelompok::class);
    }
}
