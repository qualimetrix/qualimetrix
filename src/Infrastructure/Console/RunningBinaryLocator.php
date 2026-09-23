<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

final class RunningBinaryLocator implements RunningBinaryLocatorInterface
{
    /**
     * @qmx-ignore code-smell.superglobals -- The rule's remedy is this class: it exists so that the one read of `$_SERVER` happens behind a contract and every consumer is injected instead. Anything the rule would accept here would be a second class doing the same read for this one to wrap.
     */
    public function path(): ?string
    {
        // Both, because neither is guaranteed: SCRIPT_FILENAME is absent under
        // some SAPIs, and argv[0] is whatever the caller typed — a bare name
        // found on PATH resolves to nothing here.
        $candidates = [
            $_SERVER['SCRIPT_FILENAME'] ?? null,
            $_SERVER['argv'][0] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $resolved = \is_string($candidate) && $candidate !== '' ? self::resolve($candidate) : null;

            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    public function hint(): string
    {
        return $this->path() ?? 'qmx';
    }

    /**
     * A relative candidate was relative to the directory the process started
     * in, and `--working-dir` has changed directory since: resolved now,
     * `bin/qmx` would name nothing, or a different binary in the target
     * repository.
     */
    private static function resolve(string $candidate): ?string
    {
        $resolved = str_starts_with($candidate, '/') ? realpath($candidate) : self::entryScript();

        return \is_string($resolved) && $resolved !== '' && is_file($resolved) ? $resolved : null;
    }

    /**
     * The script PHP opened to start this process, as PHP resolved it at
     * startup — absolute, and independent of any later `chdir()`. An
     * `auto_prepend_file` is opened first, so it is stepped over.
     */
    private static function entryScript(): ?string
    {
        $included = get_included_files();
        $prepend = \ini_get('auto_prepend_file');
        $prepended = \is_string($prepend) && $prepend !== '' ? realpath($prepend) : false;

        foreach ($included as $file) {
            if ($file !== $prepended) {
                return $file;
            }
        }

        return null;
    }
}
