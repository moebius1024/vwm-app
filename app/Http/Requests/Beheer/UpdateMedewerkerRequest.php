<?php

namespace App\Http\Requests\Beheer;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMedewerkerRequest extends FormRequest
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
        $medewerkerId = (int) $this->route('medewerker');

        return [
            'medewerker_nummer' => ['required', 'string', 'max:255', 'unique:medewerkers,medewerker_nummer,'.$medewerkerId],
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'functie_soort_id' => ['required', 'integer', 'exists:functie_soorten,id'],
        ];
    }
}
