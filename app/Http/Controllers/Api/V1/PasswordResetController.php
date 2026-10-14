<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\VerifyPasswordOtpRequest;
use App\Services\PasswordResetService;
use Illuminate\Http\JsonResponse;

class PasswordResetController extends Controller
{
    public function __construct(private readonly PasswordResetService $passwordReset) {}

    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $this->passwordReset->sendOtp($request->validated('email'));

        return response()->json([
            'data' => [
                'status' => 'accepted',
                'otp_expires_in' => PasswordResetService::OTP_LIFETIME,
                'resend_after' => PasswordResetService::RESEND_DELAY,
            ],
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ], 202);
    }

    public function verifyOtp(VerifyPasswordOtpRequest $request): JsonResponse
    {
        $token = $this->passwordReset->verifyOtp($request->validated('email'), $request->validated('otp'));

        return response()->json([
            'data' => ['reset_token' => $token, 'expires_in' => PasswordResetService::TOKEN_LIFETIME],
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $this->passwordReset->reset($request->validated('email'), $request->validated('reset_token'), $request->validated('password'), $request);

        return response()->json([
            'data' => ['status' => 'reset'],
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }
}
