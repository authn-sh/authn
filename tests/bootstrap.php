<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Under paratest each worker gets its own DB file via Laravel's
// ParallelTestingServiceProvider; running migrate:fresh here would race
// on the unsuffixed DB before that switch happens. Sequential runs still
// migrate here so the shared file is ready before the first test.
if (! ($_SERVER['TEST_TOKEN'] ?? $_ENV['TEST_TOKEN'] ?? null)) {
    Artisan::call('migrate:fresh', [
        '--force' => true,
        '--seed' => false,
    ]);
}

while (get_exception_handler() !== null) {
    restore_exception_handler();
}
while (get_error_handler() !== null) {
    restore_error_handler();
}
