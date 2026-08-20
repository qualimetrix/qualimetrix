<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Contract;

use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;

/** Immutable callable identity created by the shared visitor traversal scope. */
final readonly class VisitorCallableScope
{
    /**
     * @qmx-threshold code-smell.constructor-overinjection warning=14 error=14 -- Exact immutable callable scope exposes thirteen independent identity fields, in-run join position beside durable ordinal; bundling them would recreate the prohibited array record. One field of headroom, as before.
     * @qmx-threshold code-smell.long-parameter-list warning=14 error=14 -- Exact immutable callable scope constructor mirrors thirteen readonly identity fields, in-run join position beside durable ordinal; bundling them would recreate the prohibited array record. One field of headroom, as before.
     */
    public function __construct(
        public ?string $namespace,
        public ?string $class,
        public bool $anonymousClassContext,
        public string $member,
        public string $logicalFqn,
        public string $traversalKey,
        public int $startFilePos,
        public int $sourceLine,
        public CallableKind $kind,
        public ?string $anonymousSyntax,
        public ?int $classStartFilePos,
        public DeclarationOrdinal $ordinal,
        public ?DeclarationOrdinal $classOrdinal,
    ) {}
}
