<?php

namespace App\Policies;

use App\Models\Family;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class FamilyPolicy
{
    public function updateAccessMode(User $user, Family $family): Response
    {
        return $user->isAdmin() && (int) $user->family_id === (int) $family->id
            ? Response::allow()
            : Response::deny('Solo un amministratore della famiglia può cambiare la modalità di accesso.');
    }
}
