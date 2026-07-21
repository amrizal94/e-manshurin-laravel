<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['nama'])]
class Daerah extends Model
{
    protected $table = 'daerahs';

    public function desas(): HasMany
    {
        return $this->hasMany(Desa::class);
    }
}
