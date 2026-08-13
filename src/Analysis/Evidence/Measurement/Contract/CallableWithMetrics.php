<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Contract;

use InvalidArgumentException;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;

/**
 * Metrics collected for one concrete callable declaration.
 *
 * The exact declaration identity is deliberately kept separate from the
 * optional class aggregation owner: closures retain their lexical class
 * context without becoming class-owned callable metrics.
 */
final readonly class CallableWithMetrics
{
    public function __construct(
        public DeclarationPath $declarationPath,
        public CallableKind $kind,
        public ?string $anonymousSyntax,
        public ?DeclarationPath $lexicalClassContext,
        public ?LogicalClassPath $classAggregationOwner,
        public MetricBag $metrics,
        public ?int $sourceLine = null,
    ) {
        if ($kind === CallableKind::AnonymousCallable && !\in_array($anonymousSyntax, ['closure', 'arrow'], true)) {
            throw new InvalidArgumentException('Anonymous callable metrics require closure or arrow syntax metadata');
        }

        if ($kind !== CallableKind::AnonymousCallable && $anonymousSyntax !== null) {
            throw new InvalidArgumentException('Only anonymous callable metrics may carry syntax metadata');
        }

        if ($classAggregationOwner !== null && !\in_array($kind, [CallableKind::Method, CallableKind::PropertyHook], true)) {
            throw new InvalidArgumentException('Only methods and property hooks may have a class aggregation owner');
        }
    }
}
