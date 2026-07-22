<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable(['nama'])]
class Daerah extends Model
{
    use LogsActivity;

    protected $table = 'daerahs';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnlyDirty()->logExcept(['updated_at']);
    }

    public function desas(): HasMany
    {
        return $this->hasMany(Desa::class);
    }
}
