<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Filter;

use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Core\Util\NamespaceMatcher;

/**
 * Suppresses violations whose symbol namespace matches configured exclusion patterns.
 *
 * Violations on a channel its owner declared **project-scoped** are exempt:
 * `exclude_namespaces` means "I don't want metrics for this code", but a
 * project-level finding such as an architecture boundary violation is not a
 * metric — silently dropping it would let a noisy-metric exclusion double as an
 * undocumented way to disable layer-policy enforcement. Which channels those
 * are is declared, not read off the rule name's spelling; see
 * {@see ChannelFileScope}. What a user has left to suppress such a finding
 * depends on which channel it is. `architecture.layer-violation` reports real
 * code debt, so `@qmx-ignore` and a baseline entry both still apply to it. The
 * four layer-policy diagnostics beside it — coverage, unreachable layer,
 * potential shadow, empty template — are declared configuration errors: they
 * can be accepted by neither, and the only remaining answers are the
 * architecture configuration's own `exclude:` block and, for coverage
 * specifically, the `coverage: ignore` mode.
 *
 * Occurrence-style rules (code-smell and security) attach a *file* symbol path to
 * their violations, whose namespace is `null` by construction. The declaring
 * namespace is carried by the violation's subject instead, so the filter falls back
 * to `subject->toSymbolPath()->namespace` when the symbol path has none. That keeps
 * the per-occurrence declaration namespace authoritative even in a file that
 * declares multiple namespaces.
 */
final readonly class NamespaceExclusionFilter implements ViolationFilterInterface
{
    public function __construct(
        private NamespaceMatcher $namespaceMatcher,
        private ChannelFileScope $fileScope,
    ) {}

    public function shouldInclude(Violation $violation): bool
    {
        if (!$this->fileScope->isFileScoped($violation->channel())) {
            return true;
        }

        $namespace = $violation->symbolPath->namespace
            ?? $violation->subject->toSymbolPath()->namespace
            ?? '';

        return !$this->namespaceMatcher->matches($namespace);
    }
}
