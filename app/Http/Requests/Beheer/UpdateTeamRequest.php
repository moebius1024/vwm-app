<?php

namespace App\Http\Requests\Beheer;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTeamRequest extends FormRequest
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
        $teamId = (int) $this->route('team');

        return [
            'naam' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:255', 'unique:teams,code,'.$teamId],
        ];
    }
}
