<?php

namespace App\Enums;

/**
 * Modalità di accesso in sola lettura alle foto di una famiglia.
 * La scrittura resta sempre riservata ai membri autenticati.
 */
enum AccessMode: string
{
    /** Nessun accesso senza account. */
    case Private = 'private';

    /** Lettura dopo aver fornito la password condivisa della famiglia. */
    case Password = 'password';

    /** Lettura libera per chiunque abbia il link. */
    case Public = 'public';
}
