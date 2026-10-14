<?php

namespace App\Services;

use Laravel\Socialite\Two\GoogleProvider;

class GoogleOAuthProvider extends GoogleProvider
{
    protected function getTokenUrl(): string
    {
        return 'https://oauth2.googleapis.com/token';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getUserByToken(mixed $token): array
    {
        if ($this->isJwtToken($token)) {
            return parent::getUserByToken($token);
        }

        $response = $this->getHttpClient()->get('https://openidconnect.googleapis.com/v1/userinfo', [
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer '.$token,
            ],
        ]);

        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}
