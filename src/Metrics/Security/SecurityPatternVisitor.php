<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\Security;

use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Print_;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\NodeVisitorAbstract;
use Qualimetrix\Metrics\ResettableVisitorInterface;

/**
 * AST visitor that detects security patterns: SQL injection, XSS, command injection.
 *
 * Thin dispatcher that delegates detection to focused detectors:
 * - {@see SqlInjectionDetector} — SQL injection via concatenation, interpolation, SQL functions
 * - {@see XssDetector} — XSS via echo/print of unsanitized superglobals
 * - {@see CommandInjectionDetector} — command injection via exec/system/etc.
 *
 * Shared superglobal analysis logic lives in {@see SuperglobalAnalyzer}.
 */
final class SecurityPatternVisitor extends NodeVisitorAbstract implements ResettableVisitorInterface
{
    /** @var list<SecurityPatternLocation> */
    private array $locations = [];

    /** @var int Depth of Concat nesting (to only process topmost Concat) */
    private int $concatDepth = 0;

    /** @var int Depth of SQL function call nesting (to avoid duplicate detection) */
    private int $sqlFuncCallDepth = 0;

    private readonly SqlInjectionDetector $sqlInjectionDetector;
    private readonly XssDetector $xssDetector;
    private readonly CommandInjectionDetector $commandInjectionDetector;

    public function __construct()
    {
        $superglobalAnalyzer = new SuperglobalAnalyzer();
        $this->sqlInjectionDetector = new SqlInjectionDetector($superglobalAnalyzer);
        $this->xssDetector = new XssDetector($superglobalAnalyzer);
        $this->commandInjectionDetector = new CommandInjectionDetector($superglobalAnalyzer);
    }

    public function reset(): void
    {
        $this->locations = [];
        $this->concatDepth = 0;
        $this->sqlFuncCallDepth = 0;
    }

    public function enterNode(Node $node): ?int
    {
        // echo statement: check for XSS
        if ($node instanceof Node\Stmt\Echo_) {
            $this->addLocations($this->xssDetector->detectInEcho($node));

            return null;
        }

        // print expression: check for XSS
        if ($node instanceof Print_) {
            $this->addLocations($this->xssDetector->detectInPrint($node));

            return null;
        }

        // Function calls: check for SQL injection and command injection
        if ($node instanceof FuncCall) {
            if ($this->sqlInjectionDetector->isSqlFuncCall($node)) {
                $this->sqlFuncCallDepth++;
            }
            $this->addLocations($this->sqlInjectionDetector->detectInFuncCall($node));
            $this->addLocations($this->commandInjectionDetector->detectInFuncCall($node));

            return null;
        }

        // Concatenation: check for SQL injection (only at topmost Concat node)
        if ($node instanceof Concat) {
            $this->concatDepth++;
            if ($this->concatDepth === 1 && $this->sqlFuncCallDepth === 0) {
                $this->addLocations($this->sqlInjectionDetector->detectInConcat($node));
            }

            return null;
        }

        // String interpolation: check for SQL injection
        if ($node instanceof InterpolatedString) {
            $this->addLocations($this->sqlInjectionDetector->detectInInterpolation($node));

            return null;
        }

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        if ($node instanceof Concat) {
            $this->concatDepth--;
        }

        if ($node instanceof FuncCall && $this->sqlInjectionDetector->isSqlFuncCall($node)) {
            $this->sqlFuncCallDepth--;
        }

        return null;
    }

    /**
     * @return list<SecurityPatternLocation>
     */
    public function getLocations(): array
    {
        return $this->locations;
    }

    /**
     * @return list<SecurityPatternLocation>
     */
    public function getLocationsByType(string $type): array
    {
        return array_values(
            array_filter(
                $this->locations,
                static fn(SecurityPatternLocation $loc): bool => $loc->type === $type,
            ),
        );
    }

    /**
     * @param list<SecurityPatternLocation> $locations
     */
    private function addLocations(array $locations): void
    {
        foreach ($locations as $location) {
            $this->locations[] = $location;
        }
    }
}
