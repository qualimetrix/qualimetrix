<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Security;

/**
 * Detects potential Cross-Site Scripting (XSS) vulnerabilities.
 *
 * Checks for superglobals echoed/printed without sanitization
 * (htmlspecialchars, htmlentities, strip_tags, intval, int/float cast).
 */
final class XssRule extends AbstractSecurityPatternRule
{
    public const string NAME = 'security.xss';
    public const string DOCS_PAGE = 'rules/security.md';
    public const int REMEDIATION_MINUTES = 45;

    protected const string DESCRIPTION = 'Detects potential XSS vulnerabilities';
    protected const string PATTERN_TYPE = 'xss';
    protected const string MESSAGE_TEMPLATE = 'Potential XSS — use htmlspecialchars() or equivalent before outputting user input';
    protected const ?string RECOMMENDATION = 'Escape output with htmlspecialchars() or use a template engine with auto-escaping.';
}
