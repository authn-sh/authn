<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class IdempotencyMismatch extends RuntimeException {}
