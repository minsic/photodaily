<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

class InvitePolicy
{
    public function create(User $user): Response
    {
        return $user->isAdmin()
            ? Response::allow()
            : Response::deny('Solo un amministratore della famiglia può invitare nuovi membri.');
    }
}
