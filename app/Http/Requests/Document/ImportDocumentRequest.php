<?php

namespace App\Http\Requests\Document;

use Illuminate\Foundation\Http\FormRequest;

class ImportDocumentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.config('documents.import.max_kilobytes')],
            'replace_editor_data' => ['sometimes', 'boolean'],
            'editor_revision' => ['required_if:replace_editor_data,true', 'integer', 'min:1'],
        ];
    }
}
