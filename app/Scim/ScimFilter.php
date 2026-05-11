<?php

declare(strict_types=1);

namespace App\Scim;

/**
 * Parsed SCIM filter expression. Carries a single attribute path
 * (e.g. `userName`, `emails.value`, `active`), an operator (`eq` / `co`
 * / `sw`), and the coerced literal value. Compound expressions are
 * out of scope; see `ScimFilterParser`.
 */
final readonly class ScimFilter
{
    public function __construct(
        public string $attribute,
        public string $operator,
        public string|int|bool|null $value,
    ) {}
}
