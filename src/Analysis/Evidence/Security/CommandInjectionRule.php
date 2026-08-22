<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Security;

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
    public const string DOCS_PAGE = 'rules/security.md';
    public const int REMEDIATION_MINUTES = 60;

    protected const string DESCRIPTION = 'Detects potential command injection vulnerabilities';
    protected const string PATTERN_TYPE = 'command_injection';
    protected const string MESSAGE_TEMPLATE = 'Potential command injection — use escapeshellarg() before passing user input to shell commands';
    protected const ?string RECOMMENDATION = 'Use escapeshellarg() for arguments or avoid shell commands entirely.';
}
