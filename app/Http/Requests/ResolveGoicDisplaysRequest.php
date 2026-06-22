<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResolveGoicDisplaysRequest extends FormRequest
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
            'uris' => 'required|array|min:1',
            'uris.*' => 'required|string',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function uris(): array
    {
        return array_values($this->validated('uris'));
    }
}
