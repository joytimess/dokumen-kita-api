<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('users.{userGuid}', fn (User $user, string $userGuid): bool => $user->guid === $userGuid);
