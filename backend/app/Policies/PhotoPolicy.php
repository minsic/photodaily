<?php

namespace App\Policies;

use App\Models\Photo;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Isolamento per famiglia: un utente può leggere e scrivere solo le foto della
 * propria famiglia. Le foto di altre famiglie rispondono 404 (non 403) per non
 * rivelarne l'esistenza.
 *
 * I limiti del piano sull'upload sono applicati da PhotoStorage, così valgono
 * anche per il comando di import.
 */
class PhotoPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Photo $photo): Response
    {
        return $this->sameFamily($user, $photo);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Photo $photo): Response
    {
        return $this->sameFamily($user, $photo);
    }

    public function delete(User $user, Photo $photo): Response
    {
        return $this->sameFamily($user, $photo);
    }

    private function sameFamily(User $user, Photo $photo): Response
    {
        return $user->sharesFamilyWith($photo)
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
