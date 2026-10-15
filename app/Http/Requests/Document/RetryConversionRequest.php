<?php

namespace App\Http\Requests\Document;

use Illuminate\Foundation\Http\FormRequest;

class RetryConversionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'replace_editor_data' => ['sometimes', 'boolean'],
            'editor_revision' => ['required_if:replace_editor_data,true', 'integer', 'min:1'],
        ];
    }
}
