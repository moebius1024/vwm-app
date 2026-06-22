<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class FollowGoicRequest extends FormRequest
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
            'case_id' => ['required', 'integer'],
        ];
    }

    public function caseId(): int
    {
        return (int) $this->validated('case_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function followInput(): array
    {
        $input = [];
        foreach (['bron_goic_uri', 'bron_goic_uris'] as $key) {
            if ($this->exists($key)) {
                $input[$key] = $this->input($key);
            }
        }

        return $input;
    }
}
