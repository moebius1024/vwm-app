<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['medewerker_id', 'naam', 'identifier'])]
class Persoon extends Model
{
    public function medewerker(): BelongsTo
    {
        return $this->belongsTo(Medewerker::class);
    }
}
