<?php

namespace App\Policies;

use App\Models\SignerAssignment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class SignerAssignmentPolicy
{
    public function __construct(private readonly DocumentVersionPolicy $versions) {}

    public function manage(User $user, SignerAssignment $assignment): Response
    {
        return $this->versions->update($user, $assignment->version);
    }
}
