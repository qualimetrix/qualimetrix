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
            if (!\is_string($candidate) || $candidate === '') {
                continue;
            }

            $resolved = realpath($candidate);

            if ($resolved !== false && is_file($resolved)) {
                return $resolved;
            }
        }

        return null;
    }

    public function hint(): string
    {
        return $this->path() ?? 'qmx';
    }
}
