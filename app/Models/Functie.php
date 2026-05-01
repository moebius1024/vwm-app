<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['medewerker_id', 'functie_soort_id'])]
class Functie extends Model
{
    public function medewerker(): BelongsTo
    {
        return $this->belongsTo(Medewerker::class);
    }

    public function functieSoort(): BelongsTo
    {
        return $this->belongsTo(FunctieSoort::class);
    }
}
