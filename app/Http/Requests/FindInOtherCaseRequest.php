<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FindInOtherCaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'case' => ['required', 'integer'],
            'goic' => ['required', 'integer'],
            'case_soort' => ['nullable', 'integer'],
        ];
    }
}
