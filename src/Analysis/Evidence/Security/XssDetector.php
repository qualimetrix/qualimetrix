<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Security;

use PhpParser\Node\Expr\Print_;
use PhpParser\Node\Stmt\Echo_;

/**
 * Detects XSS (Cross-Site Scripting) patterns: echo/print of an expression
 * whose value carries a superglobal — see {@see SuperglobalAnalyzer} for
 * which wrappers are looked through and which (sanitizers, `(int)`/`(float)`
 * casts, any other call) end the search.
 */
final readonly class XssDetector
{
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
            $varName = $this->superglobalAnalyzer->findSuperglobal($expr);
            if ($varName !== null) {
                $locations[] = new SecurityPatternLocation(
                    type: 'xss',
                    line: $node->getStartLine(),
                    context: "echo \${$varName} without sanitization",
                );
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
        $varName = $this->superglobalAnalyzer->findSuperglobal($node->expr);
        if ($varName === null) {
            return [];
        }

        return [
            new SecurityPatternLocation(
                type: 'xss',
                line: $node->getStartLine(),
                context: "print \${$varName} without sanitization",
            ),
        ];
    }
}
