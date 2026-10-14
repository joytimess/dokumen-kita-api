<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

#[Signature('auth:prune-password-challenges')]
#[Description('Delete expired password reset challenges')]
class PrunePasswordResetChallenges extends Command
{
    public function handle(): int
    {
        $deleted = DB::table('password_reset_challenges')
            ->where('otp_expires_at', '<=', now())
            ->where(function (Builder $query): void {
                $query->whereNull('reset_token_expires_at')->orWhere('reset_token_expires_at', '<=', now());
            })->delete();

        $this->info("Deleted {$deleted} expired password reset challenges.");

        return self::SUCCESS;
    }
}
