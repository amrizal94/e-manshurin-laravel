<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable(['desa_id', 'nama'])]
class Kelompok extends Model
{
    use LogsActivity;

    protected $table = 'kelompoks';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnlyDirty()->logExcept(['updated_at']);
    }

    public function desa(): BelongsTo
    {
        return $this->belongsTo(Desa::class);
    }
}
