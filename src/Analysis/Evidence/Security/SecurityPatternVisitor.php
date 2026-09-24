<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Security;

use LogicException;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Print_;
use PhpParser\Node\Expr\ShellExec;
use PhpParser\NodeVisitorAbstract;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationIndexAwareInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ResettableVisitorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\VisitorMethodTrackingTrait;

/**
 * AST visitor that detects security patterns: SQL injection, XSS, command injection.
 *
 * Thin dispatcher that delegates detection to focused detectors:
 * - {@see SqlInjectionDetector} — SQL injection via concatenation, interpolation, SQL functions
 * - {@see XssDetector} — XSS via echo/print of unsanitized superglobals
 * - {@see CommandInjectionDetector} — command injection via exec/system/etc. and backticks
 *
 * Shared superglobal analysis logic lives in {@see SuperglobalAnalyzer}.
 *
 * One SQL injection finding is reported per query: a query nested in a
 * reported one is reported again only for a superglobal read the enclosing
 * query did not reach, such as a subquery built inside a call.
 */
final class SecurityPatternVisitor extends NodeVisitorAbstract implements DeclarationIndexAwareInterface, ResettableVisitorInterface
{
    use VisitorMethodTrackingTrait;

    /** @var list<SecurityPatternLocation> */
    private array $locations = [];

    /**
     * Object ids of the superglobal reads a reported query reached. A
     * superglobal search looks through concatenation and interpolation, so a
     * query nested in a reported one reaches the same reads again.
     *
     * @var array<int, true>
     */
    private array $reportedReads = [];

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
        $this->reportedReads = [];
        $this->resetVisitorMethodContext();
    }

    public function enterNode(Node $node): ?int
    {
        $this->enterVisitorMethodContext($node);
        $this->detectSqlInjection($node);
        $this->addLocations(match (true) {
            $node instanceof Node\Stmt\Echo_ => $this->xssDetector->detectInEcho($node),
            $node instanceof Print_ => $this->xssDetector->detectInPrint($node),
            $node instanceof FuncCall => $this->commandInjectionDetector->detectInFuncCall($node),
            $node instanceof ShellExec => $this->commandInjectionDetector->detectInShellExec($node),
            default => [],
        });

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        $this->leaveVisitorMethodContext($node);

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

    private function detectSqlInjection(Node $node): void
    {
        $locations = $this->sqlInjectionDetector->detect($node, $this->reportedReads);
        if ($locations === []) {
            return;
        }

        foreach ($this->sqlInjectionDetector->reads($node) as $read) {
            $this->reportedReads[spl_object_id($read)] = true;
        }

        $this->addLocations($locations);
    }

    /**
     * @param list<SecurityPatternLocation> $locations
     */
    private function addLocations(array $locations): void
    {
        foreach ($locations as $location) {
            $this->locations[] = new SecurityPatternLocation(
                type: $location->type,
                line: $location->line,
                context: $location->context,
                subjectId: $this->currentFileEntrySubjectId(),
            );
        }
    }

    /** @return array<string, int|string> */
    public function getSubjectComponents(SecurityPatternLocation $location): array
    {
        if ($location->subjectId === null) {
            throw new LogicException('Security pattern location is missing its subject reference');
        }

        return $this->fileEntrySubjectComponents($location->subjectId);
    }
}
