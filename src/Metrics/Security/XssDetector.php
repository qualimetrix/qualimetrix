<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\Security;

use PhpParser\Node\Expr\Print_;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Stmt\Echo_;

/**
 * Detects XSS (Cross-Site Scripting) patterns: unsanitized superglobal output.
 *
 * Detection vectors:
 * - echo/print of superglobals without htmlspecialchars/htmlentities/strip_tags/intval
 * - echo/print of interpolated strings containing superglobals
 * - echo/print of concatenations containing unsanitized superglobals
 */
final readonly class XssDetector
{
    /** @var list<string> XSS sanitization functions */
    private const XSS_SANITIZERS = [
        'htmlspecialchars',
        'htmlentities',
        'strip_tags',
        'intval',
    ];

    public function __construct(
        private SuperglobalAnalyzer $superglobalAnalyzer,
    ) {}

    /**
     * Detect XSS in an echo statement.
     *
     * @return list<SecurityPatternLocation>
     */
    public function detectInEcho(Echo_ $node): array
    {
        $locations = [];

        foreach ($node->exprs as $expr) {
            if ($this->superglobalAnalyzer->isUnsanitizedSuperglobal($expr, self::XSS_SANITIZERS)) {
                $varName = $this->superglobalAnalyzer->getSuperglobalName($expr);
                $locations[] = new SecurityPatternLocation(
                    type: 'xss',
                    line: $node->getStartLine(),
                    context: "echo \${$varName} without sanitization",
                );
            } elseif ($expr instanceof InterpolatedString) {
                $varName = $this->superglobalAnalyzer->findSuperglobalInInterpolatedString($expr);
                if ($varName !== null) {
                    $locations[] = new SecurityPatternLocation(
                        type: 'xss',
                        line: $node->getStartLine(),
                        context: "echo \${$varName} without sanitization",
                    );
                }
            } elseif ($this->superglobalAnalyzer->containsUnsanitizedSuperglobalInExpr($expr, self::XSS_SANITIZERS)) {
                $varName = $this->superglobalAnalyzer->findUnsanitizedSuperglobalName($expr, self::XSS_SANITIZERS);
                if ($varName !== null) {
                    $locations[] = new SecurityPatternLocation(
                        type: 'xss',
                        line: $node->getStartLine(),
                        context: "echo \${$varName} without sanitization",
                    );
                }
            }
        }

        return $locations;
    }

    /**
     * Detect XSS in a print expression.
     *
     * @return list<SecurityPatternLocation>
     */
    public function detectInPrint(Print_ $node): array
    {
        if ($this->superglobalAnalyzer->isUnsanitizedSuperglobal($node->expr, self::XSS_SANITIZERS)) {
            $varName = $this->superglobalAnalyzer->getSuperglobalName($node->expr);

            return [
                new SecurityPatternLocation(
                    type: 'xss',
                    line: $node->getStartLine(),
                    context: "print \${$varName} without sanitization",
                ),
            ];
        }

        if ($node->expr instanceof InterpolatedString) {
            $varName = $this->superglobalAnalyzer->findSuperglobalInInterpolatedString($node->expr);
            if ($varName !== null) {
                return [
                    new SecurityPatternLocation(
                        type: 'xss',
                        line: $node->getStartLine(),
                        context: "print \${$varName} without sanitization",
                    ),
                ];
            }
        }

        if ($this->superglobalAnalyzer->containsUnsanitizedSuperglobalInExpr($node->expr, self::XSS_SANITIZERS)) {
            $varName = $this->superglobalAnalyzer->findUnsanitizedSuperglobalName($node->expr, self::XSS_SANITIZERS);
            if ($varName !== null) {
                return [
                    new SecurityPatternLocation(
                        type: 'xss',
                        line: $node->getStartLine(),
                        context: "print \${$varName} without sanitization",
                    ),
                ];
            }
        }

        return [];
    }
}
