<?php

namespace App\Http\Requests\Document;

use Illuminate\Foundation\Http\FormRequest;

class ClaimInvitationRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['token' => ['required', 'string', 'size:64', 'regex:/\\A[a-f0-9]{64}\\z/']];
    }
}
