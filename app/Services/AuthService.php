<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthService
{
    /**
     * @param  array{name: string, email: string, password: string}  $data
     */
    public function register(array $data, Request $request): User
    {
        $user = User::create($data);

        event(new Registered($user));

        $this->guard()->login($user);
        $request->session()->regenerate();

        return $user;
    }

    /**
     * @param  array{email: string, password: string}  $credentials
     *
     * @throws ValidationException
     */
    public function login(array $credentials, bool $remember, Request $request): User
    {
        if (! $this->guard()->attempt($credentials, $remember)) {
            throw ValidationException::withMessages(['email' => ['Email atau kata sandi salah.']]);
        }

        $request->session()->regenerate();

        return $this->guard()->user();
    }

    public function logout(Request $request): void
    {
        $this->guard()->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    private function guard(): StatefulGuard
    {
        return Auth::guard('web');
    }
}
