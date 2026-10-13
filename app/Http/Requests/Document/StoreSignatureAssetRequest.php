<?php

namespace App\Http\Requests\Document;

use Illuminate\Foundation\Http\FormRequest;

class StoreSignatureAssetRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'extensions:png,jpg,jpeg,webp',
                'max:'.config('documents.signature_assets.max_kilobytes'),
                'dimensions:min_width=16,min_height=16,max_width='.config('documents.signature_assets.max_width').',max_height='.config('documents.signature_assets.max_height')],
            'source_type' => ['required', 'string', 'in:drawn,upload'],
        ];
    }
}
