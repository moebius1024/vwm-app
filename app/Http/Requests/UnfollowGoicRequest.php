<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UnfollowGoicRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'case_id' => 'required|integer',
        ];
    }

    public function caseId(): int
    {
        return (int) $this->validated('case_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function unfollowInput(): array
    {
        return $this->has('association_uri')
            ? ['association_uri' => $this->input('association_uri')]
            : [];
    }
}
