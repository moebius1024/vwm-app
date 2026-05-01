<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

#[Fillable(['naam', 'code'])]
class Team extends Model
{
    public function medewerkers(): HasMany
    {
        return $this->hasMany(Medewerker::class);
    }

    public function functies(): HasManyThrough
    {
        return $this->hasManyThrough(Functie::class, Medewerker::class);
    }
}
