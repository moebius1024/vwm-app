<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['naam', 'code', 'omschrijving'])]
class Rechtsgrond extends Model
{
    public function autorisatieRollen(): HasMany
    {
        return $this->hasMany(AutorisatieRol::class);
    }

    public function caseSoorten(): HasMany
    {
        return $this->hasMany(CaseSoort::class);
    }
}
