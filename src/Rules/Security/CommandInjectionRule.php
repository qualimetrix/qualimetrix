<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Security;

use AiMessDetector\Core\Violation\Severity;

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
}
