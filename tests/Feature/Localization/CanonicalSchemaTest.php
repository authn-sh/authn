<?php

declare(strict_types=1);

use App\Localization\CanonicalSchema;

it('exposes a non-empty canonical key list', function (): void {
    $keys = CanonicalSchema::keys();

    expect($keys)->not->toBeEmpty();
    expect($keys)->toContain('signIn.start.title');
    expect($keys)->toContain('formButtonPrimary');
});

it('extracts ICU-style {placeholders} from the canonical value', function (): void {
    expect(CanonicalSchema::placeholdersIn('signIn.start.title'))->toBe(['applicationName']);
    expect(CanonicalSchema::placeholdersIn('signIn.emailCode.subtitle'))->toBe(['identifier']);
    expect(CanonicalSchema::placeholdersIn('formButtonPrimary'))->toBe([]);
    expect(CanonicalSchema::placeholdersIn('paginationRowText__displaying'))->toBe(['start', 'end', 'total']);
});

it('every shipped locale exposes the same key set as en-US', function (): void {
    $canonical = CanonicalSchema::keys();
    foreach (CanonicalSchema::SHIPPED_LOCALES as $locale) {
        $keys = array_keys(CanonicalSchema::catalog($locale));
        sort($keys);
        expect($keys)->toBe($canonical, "{$locale} catalog is missing or has extra keys vs. en-US");
    }
});

it('every shipped non-en-US locale preserves the {placeholders} present in the canonical value', function (): void {
    $canonical = CanonicalSchema::catalog('en-US');
    foreach (CanonicalSchema::SHIPPED_LOCALES as $locale) {
        if ($locale === 'en-US') {
            continue;
        }
        $catalog = CanonicalSchema::catalog($locale);
        foreach ($canonical as $key => $template) {
            $expected = CanonicalSchema::placeholdersInString($template);
            sort($expected);
            $actual = CanonicalSchema::placeholdersInString($catalog[$key] ?? '');
            sort($actual);
            expect($actual)->toBe($expected, "{$locale}.{$key} dropped a placeholder vs. en-US");
        }
    }
});
