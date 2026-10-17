<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UploadProfilePhotoRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'photo' => [
                'required', 'image', 'mimes:jpg,jpeg,png,webp',
                'max:'.config('documents.signature_assets.max_kilobytes'),
                'dimensions:max_width='.config('documents.signature_assets.max_width').',max_height='.config('documents.signature_assets.max_height'),
            ],
        ];
    }
}
