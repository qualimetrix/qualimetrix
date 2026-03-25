<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\Security;

use Qualimetrix\Core\Violation\Severity;

/**
 * Detects potential command injection vulnerabilities.
 *
 * Checks for superglobals used as arguments in command execution functions
 * (exec, system, passthru, shell_exec, proc_open, popen) without sanitization
 * (escapeshellarg, escapeshellcmd).
 */
final class CommandInjectionRule extends AbstractSecurityPatternRule
{
    public const string NAME = 'security.command-injection';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Detects potential command injection vulnerabilities';
    }

    protected function getPatternType(): string
    {
        return 'command_injection';
    }

    protected function getSeverity(): Severity
    {
        return Severity::Error;
    }

    protected function getMessageTemplate(): string
    {
        return 'Potential command injection — use escapeshellarg() before passing user input to shell commands';
    }

    protected function getRecommendation(): string
    {
        return 'Use escapeshellarg() for arguments or avoid shell commands entirely.';
    }
}
