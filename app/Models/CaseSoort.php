<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['naam', 'code', 'rechtsgrond_id'])]
class CaseSoort extends Model
{
    protected $table = 'case_soorten';

    public function rechtsgrond(): BelongsTo
    {
        return $this->belongsTo(Rechtsgrond::class);
    }
}
