<?php

namespace App\Http\Requests\Document;

use App\Rules\ImageDimensions;
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
                new ImageDimensions(1, 1, (int) config('documents.page_decorations.max_width'), (int) config('documents.page_decorations.max_height'))],
            'pages' => ['sometimes', 'array'],
        ];
    }
}
