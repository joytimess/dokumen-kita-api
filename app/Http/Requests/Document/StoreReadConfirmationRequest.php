<?php

namespace App\Http\Requests\Document;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreReadConfirmationRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('act', $this->route('assignment'));

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'confirmed' => ['required', 'accepted'],
            'version_content_hash' => ['required', 'string', 'regex:/\\A[a-f0-9]{64}\\z/'],
        ];
    }
}
