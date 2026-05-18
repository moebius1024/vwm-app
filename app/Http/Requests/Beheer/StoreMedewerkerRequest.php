<?php

namespace App\Http\Requests\Beheer;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreMedewerkerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'medewerker_nummer' => ['required', 'string', 'max:255', 'unique:medewerkers,medewerker_nummer'],
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'functie_soort_id' => ['required', 'integer', 'exists:functie_soorten,id'],
        ];
    }
}
