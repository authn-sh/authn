<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Default hashing driver
|--------------------------------------------------------------------------
|
| authn.sh stores user passwords with Argon2id. Bcrypt is kept available
| for compatibility with imported `password_digest`s in BAPI user-create
| (PLAN §4.2 / AU-13).
*/

return [

    'driver' => env('HASH_DRIVER', 'argon2id'),

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => true,
        'limit' => null,
    ],

    'argon' => [
        'memory' => 65536,    // 64 MB — the OWASP-recommended floor.
        'threads' => 1,
        'time' => 4,
        'verify' => true,
    ],

    'rehash_on_login' => true,

];
