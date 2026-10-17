<?php

namespace App\Http\Requests\Document;

use App\Models\DocumentCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpsertDocumentCategoryRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'color_token' => ['required', Rule::in(DocumentCategory::COLOR_TOKENS)],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('name') || ! is_string($this->input('name'))) {
                return;
            }

            $query = DocumentCategory::where('owner_guid', $this->user()->guid)
                ->where('normalized_name', mb_strtolower(trim($this->input('name'))));
            $routeCategory = $this->route('category');

            if (is_string($routeCategory)) {
                $query->where('guid', '!=', $routeCategory);
            }

            if ($query->exists()) {
                $validator->errors()->add('name', 'Nama kategori sudah digunakan.');
            }
        }];
    }
}
