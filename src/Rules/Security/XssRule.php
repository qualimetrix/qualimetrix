<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\Security;

use Qualimetrix\Core\Violation\Severity;

/**
 * Detects potential Cross-Site Scripting (XSS) vulnerabilities.
 *
 * Checks for superglobals echoed/printed without sanitization
 * (htmlspecialchars, htmlentities, strip_tags, intval, int/float cast).
 */
final class XssRule extends AbstractSecurityPatternRule
{
    public const string NAME = 'security.xss';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Detects potential XSS vulnerabilities';
    }

    protected function getPatternType(): string
    {
        return 'xss';
    }

    protected function getSeverity(): Severity
    {
        return Severity::Error;
    }

    protected function getMessageTemplate(): string
    {
        return 'Potential XSS — use htmlspecialchars() or equivalent before outputting user input';
    }

    protected function getRecommendation(): string
    {
        return 'Escape output with htmlspecialchars() or use a template engine with auto-escaping.';
    }
}
