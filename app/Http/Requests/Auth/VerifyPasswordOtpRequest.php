<?php

namespace App\Http\Requests\Auth;

class VerifyPasswordOtpRequest extends ForgotPasswordRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [...parent::rules(), 'otp' => ['required', 'string', 'regex:/\\A[0-9]{6}\\z/']];
    }
}
