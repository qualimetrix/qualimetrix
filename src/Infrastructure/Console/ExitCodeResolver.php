<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Configuration\ConfigurationProviderInterface;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;

/**
 * Determines process exit code based on violation severities and failOn configuration.
 */
final readonly class ExitCodeResolver
{
    public function __construct(
        private ConfigurationProviderInterface $configurationProvider,
    ) {}

    /**
     * Determines exit code based on violation severity and failOn configuration.
     *
     * Default (null): only errors cause non-zero exit code (same as --fail-on=error).
     * When failOn is Severity::Warning, warnings also cause non-zero exit code.
     *
     * @param list<Violation> $violations
     */
    public function resolve(array $violations): int
    {
        $failOn = $this->configurationProvider->hasConfiguration()
            ? $this->configurationProvider->getConfiguration()->failOn
            : null;

        // --fail-on=none: never fail on violations
        if ($failOn === false) {
            return 0;
        }

        // Default is --fail-on=error (null treated as Severity::Error)
        $effectiveFailOn = $failOn ?? Severity::Error;

        $hasErrors = false;
        $hasWarnings = false;

        foreach ($violations as $violation) {
            if ($violation->severity === Severity::Error) {
                $hasErrors = true;
                break;
            }
            if ($violation->severity === Severity::Warning) {
                $hasWarnings = true;
            }
        }

        if ($hasErrors) {
            return Severity::Error->getExitCode();
        }

        if ($hasWarnings && $effectiveFailOn === Severity::Warning) {
            return Severity::Warning->getExitCode();
        }

        return 0;
    }
}
