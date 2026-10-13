<?php

namespace App\Http\Requests\Document;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class UpdatePlaceholdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('manage', $this->route('assignment'));

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'placeholders' => ['present', 'array', 'list', 'max:'.config('documents.signing.max_placeholders_per_signer')],
            'placeholders.*' => ['required', 'array:page_number,x,y,width,height'],
            'placeholders.*.page_number' => ['required', 'integer', 'min:1'],
            'placeholders.*.x' => ['required', 'numeric', 'between:0,1', 'decimal:0,6'],
            'placeholders.*.y' => ['required', 'numeric', 'between:0,1', 'decimal:0,6'],
            'placeholders.*.width' => ['required', 'numeric', 'between:0.000001,1', 'decimal:0,6'],
            'placeholders.*.height' => ['required', 'numeric', 'between:0.000001,1', 'decimal:0,6'],
        ];
    }

    /** @return array<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            foreach ($this->input('placeholders') as $index => $placeholder) {
                if (round(($placeholder['x'] + $placeholder['width']) * 1_000_000) > 1_000_000
                    || round(($placeholder['y'] + $placeholder['height']) * 1_000_000) > 1_000_000) {
                    $validator->errors()->add("placeholders.$index", 'Placeholder harus berada di dalam halaman.');
                }
            }
        }];
    }
}
