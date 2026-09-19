<?php

namespace App\Policies;

use App\Models\Invite;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class InvitePolicy
{
    public function viewAny(User $user): Response
    {
        return $this->onlyAdmins($user, 'vedere gli inviti della famiglia');
    }

    public function create(User $user): Response
    {
        return $this->onlyAdmins($user, 'invitare nuovi membri');
    }

    public function delete(User $user, Invite $invite): Response
    {
        // Gli inviti di altre famiglie, per chi guarda, non esistono.
        return $user->sharesFamilyWith($invite)
            ? $this->onlyAdmins($user, 'annullare un invito')
            : Response::denyAsNotFound();
    }

    private function onlyAdmins(User $user, string $action): Response
    {
        return $user->isAdmin()
            ? Response::allow()
            : Response::deny("Solo un amministratore della famiglia può {$action}.");
    }
}
