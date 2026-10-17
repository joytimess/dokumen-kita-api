<?php

namespace App\Http\Requests\Document;

use App\Enums\VersionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ListDocumentsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(VersionStatus::class)],
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
            'category_guid' => ['sometimes', 'nullable', 'uuid'],
            'uncategorized' => ['sometimes', 'boolean'],
            'visibility' => ['sometimes', 'in:active,hidden'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('q') && is_string($this->input('q'))) {
            $this->merge(['q' => trim($this->input('q'))]);
        }

        if (is_string($this->input('uncategorized'))) {
            $this->merge(['uncategorized' => filter_var($this->input('uncategorized'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $this->input('uncategorized')]);
        }
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->filled('category_guid') && $this->boolean('uncategorized')) {
                $validator->errors()->add('category_guid', 'Pilih kategori atau tanpa kategori, bukan keduanya.');
            }
        }];
    }
}
