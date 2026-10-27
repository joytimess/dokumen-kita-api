<?php

namespace App\Http\Requests\Document;

use App\Rules\ImageDimensions;
use Illuminate\Foundation\Http\FormRequest;

class StoreSignatureAssetRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['bail', 'required', 'file', 'max:'.config('documents.signature_assets.max_kilobytes'),
                'image', 'mimes:png,jpg,jpeg,webp', 'mimetypes:image/png,image/jpeg,image/webp', 'extensions:png,jpg,jpeg,webp',
                new ImageDimensions(16, 16, (int) config('documents.signature_assets.max_width'), (int) config('documents.signature_assets.max_height'))],
            'source_type' => ['required', 'string', 'in:drawn,upload'],
        ];
    }
}
