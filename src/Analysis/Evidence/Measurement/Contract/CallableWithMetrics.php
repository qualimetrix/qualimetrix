<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Contract;

use InvalidArgumentException;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationPath;

/**
 * Metrics collected for one concrete callable declaration.
 *
 * The exact declaration identity is deliberately kept separate from the
 * optional class aggregation owner: closures retain their lexical class
 * context without becoming class-owned callable metrics.
 *
 * `startFilePos` is the in-run join key, not part of the identity: producers
 * of the same declaration are matched by it, and it never reaches a stored
 * key.
 */
final readonly class CallableWithMetrics
{
    /**
     * @qmx-threshold code-smell.constructor-overinjection warning=10 error=10 -- Typed declaration metadata and collected payload are retained together; lexical context is independent of exact named-class ownership.
     * @qmx-threshold code-smell.long-parameter-list warning=10 error=10 -- Typed declaration metadata and collected payload are retained together; lexical context is independent of exact named-class ownership.
     */
    public function __construct(
        public DeclarationPath $declarationPath,
        public int $startFilePos,
        public CallableKind $kind,
        public ?string $anonymousSyntax,
        public ?DeclarationPath $lexicalClassContext,
        public ?DeclarationPath $classAggregationOwner,
        public MetricBag $metrics,
        public ?int $sourceLine = null,
        public bool $anonymousClassContext = false,
    ) {
        if ($kind === CallableKind::AnonymousCallable && !\in_array($anonymousSyntax, ['closure', 'arrow'], true)) {
            throw new InvalidArgumentException('Anonymous callable metrics require closure or arrow syntax metadata');
        }

        if ($kind !== CallableKind::AnonymousCallable && $anonymousSyntax !== null) {
            throw new InvalidArgumentException('Only anonymous callable metrics may carry syntax metadata');
        }

        $kind->assertClassAggregationOwner($classAggregationOwner, $anonymousClassContext);
    }
}
