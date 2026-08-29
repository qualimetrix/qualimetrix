<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * The one place the gate's entry points read their arguments.
 *
 * PHPStan reports `$argv` as possibly undefined in every entry point, and it is
 * right to: the variable exists only because the SAPI populated it. Measured
 * 2026-08-23 on PHP 8.4 — the CLI SAPI populates `$argv` and `$_SERVER['argv']`
 * even under `-d register_argc_argv=0`, so the three reports were false alarms
 * about that mechanism. They are still worth closing here rather than silencing:
 * under any other SAPI the entry points would fail on a null argument list with
 * a TypeError pointing at the wrong place, and the gate is the tool the other
 * steps' correctness is proved with.
 */
final class CommandLine
{
    /** @return list<string> */
    public static function arguments(): array
    {
        $arguments = $_SERVER['argv'] ?? null;

        if (!\is_array($arguments) || $arguments === []) {
            fwrite(
                \STDERR,
                "This is a command-line tool: \$_SERVER['argv'] is empty, so the SAPI running it is not the CLI.\n",
            );
            exit(3);
        }

        $parsed = [];

        foreach ($arguments as $argument) {
            if (!\is_string($argument)) {
                fwrite(\STDERR, "\$_SERVER['argv'] holds a non-string argument.\n");
                exit(3);
            }

            $parsed[] = $argument;
        }

        return $parsed;
    }
}
