<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Contract;

use Qualimetrix\Core\Symbol\CallableKind;

/** Immutable callable identity created by the shared visitor traversal scope. */
final readonly class VisitorCallableScope
{
    /**
     * @qmx-threshold code-smell.constructor-overinjection warning=12 error=12 -- Exact immutable callable scope exposes eleven independent identity fields; bundling them would recreate the prohibited array record.
     * @qmx-threshold code-smell.long-parameter-list warning=12 error=12 -- Exact immutable callable scope constructor mirrors eleven readonly identity fields; bundling them would recreate the prohibited array record.
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
    ) {}
}
