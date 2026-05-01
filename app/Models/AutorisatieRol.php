<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['functie_soort_id', 'rechtsgrond_id', 'naam', 'code'])]
class AutorisatieRol extends Model
{
    protected $table = 'autorisatie_rollen';

    public function functieSoort(): BelongsTo
    {
        return $this->belongsTo(FunctieSoort::class);
    }

    public function rechtsgrond(): BelongsTo
    {
        return $this->belongsTo(Rechtsgrond::class);
    }
}
