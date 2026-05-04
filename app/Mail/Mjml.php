<?php

declare(strict_types=1);

namespace App\Mail;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Thin wrapper over the `mjml` Node CLI. Used by `EmailTemplate::saving` to
 * compile MJML source → HTML on operator edits, so render-time only does
 * placeholder substitution. The binary path is configurable via
 * `config('authn-mail.mjml_binary')`; the wrapper falls back to the source
 * markup when MJML isn't installed (test environments and ad-hoc setups).
 */
final class Mjml
{
    /**
     * Compile MJML to HTML. Returns the input unchanged when the CLI isn't
     * available — the caller can decide whether to fail or accept the raw
     * markup. The renderer treats the output as HTML either way.
     */
    public static function compile(string $mjml): string
    {
        $binary = (string) config('authn-mail.mjml_binary', 'mjml');

        try {
            $process = new Process([$binary, '-i', '-s']);
            $process->setInput($mjml);
            $process->setTimeout(15);
            $process->run();
        } catch (\Throwable) {
            return $mjml;
        }

        if (! $process->isSuccessful()) {
            return $mjml;
        }

        return trim($process->getOutput());
    }

    /**
     * @internal exposed for tests that want to assert the binary is on PATH.
     */
    public static function binaryAvailable(): bool
    {
        $binary = (string) config('authn-mail.mjml_binary', 'mjml');
        $process = new Process([$binary, '--version']);
        try {
            $process->setTimeout(5)->run();
        } catch (ProcessFailedException) {
            return false;
        }

        return $process->isSuccessful();
    }
}
