<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['naam', 'code'])]
class FunctieSoort extends Model
{
    protected $table = 'functie_soorten';

    public function functies(): HasMany
    {
        return $this->hasMany(Functie::class);
    }

    public function autorisatieRollen(): HasMany
    {
        return $this->hasMany(AutorisatieRol::class);
    }
}
