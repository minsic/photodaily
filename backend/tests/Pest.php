<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test
|--------------------------------------------------------------------------
|
| I test nuovi sono scritti con Pest; quelli già esistenti restano classi
| PHPUnit e girano anche con `vendor/bin/pest` e con `php artisan test`.
| Le classi PHPUnit estendono già TestCase: qui si applica solo ai file Pest.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature/Pest');
