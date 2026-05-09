<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

Artisan::call('migrate:fresh', [
    '--force' => true,
    '--seed' => false,
]);

while (get_exception_handler() !== null) {
    restore_exception_handler();
}
while (get_error_handler() !== null) {
    restore_error_handler();
}
