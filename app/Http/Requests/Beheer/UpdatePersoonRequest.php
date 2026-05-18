<?php

namespace App\Http\Requests\Beheer;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;

class UpdatePersoonRequest extends FormRequest
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
        $persoonId = (int) $this->route('persoon');
        $persoon = DB::table('personen')->where('id', $persoonId)->first(['medewerker_id']);
        $medewerkerId = (int) ($persoon->medewerker_id ?? 0);

        return [
            'naam' => ['required', 'string', 'max:255'],
            'medewerker_nummer' => ['nullable', 'string', 'max:255', 'unique:medewerkers,medewerker_nummer,'.$medewerkerId],
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'functie_soort_id' => ['nullable', 'integer', 'exists:functie_soorten,id', 'required_with:medewerker_nummer'],
        ];
    }
}
