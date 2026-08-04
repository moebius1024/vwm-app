<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class LinkExistingIdentityRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'source_case_id' => ['required', 'integer'],
            'source_goic_id' => ['required', 'integer'],
            'candidate_goic_id' => ['required', 'integer', 'different:source_goic_id'],
            'confirmed' => ['accepted'],
        ];
    }
}
