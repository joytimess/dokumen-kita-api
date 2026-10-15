<?php

namespace App\Http\Requests\Document;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVersionDecorationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'editor_revision' => ['required', 'integer', 'min:1'],
            'file' => ['sometimes', 'file', 'mimes:png,jpg,jpeg,webp', 'extensions:png,jpg,jpeg,webp',
                'max:'.config('documents.page_decorations.max_kilobytes'),
                'dimensions:min_width=1,min_height=1,max_width='.config('documents.page_decorations.max_width').',max_height='.config('documents.page_decorations.max_height')],
            'pages' => ['sometimes', 'array'],
        ];
    }
}
