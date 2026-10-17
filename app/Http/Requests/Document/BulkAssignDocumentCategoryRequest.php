<?php

namespace App\Http\Requests\Document;

use Illuminate\Foundation\Http\FormRequest;

class BulkAssignDocumentCategoryRequest extends FormRequest
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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'document_guids' => ['required', 'array', 'min:1', 'max:100'],
            'document_guids.*' => ['required', 'uuid', 'distinct'],
            'category_guid' => ['present', 'nullable', 'uuid'],
        ];
    }
}
