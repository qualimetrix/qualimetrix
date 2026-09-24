<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Filter;

use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Core\Pattern\NamespaceMatcher;
use Qualimetrix\Core\Symbol\SymbolType;

/**
 * Suppresses findings whose symbol namespace matches configured exclusion patterns.
 *
 * Findings on a channel its owner declared **project-scoped** are exempt:
 * `suppress_namespaces` means "I don't want metrics for this code", but a
 * project-level finding such as an architecture boundary violation is not a
 * metric — silently dropping it would let a noisy-metric exclusion double as an
 * undocumented way to disable layer-policy enforcement. Which channels those
 * are is declared, not read off the rule name's spelling; see
 * {@see ChannelFileScope}. What a user has left to suppress such a finding
 * depends on which channel it is. `architecture.layer-violation` reports real
 * code debt, so `@qmx-ignore` and a baseline entry both still apply to it. The
 * layer-policy diagnostics beside it — coverage, unreachable layer, potential
 * shadow, empty template, pending layer matched — are declared configuration errors: they
 * can be accepted by neither, and the only remaining answers are the
 * architecture configuration's own `exclude:` block and, for coverage
 * specifically, the `coverage-gap: ignore` mode.
 *
 * Occurrence-style rules (code-smell and security) attach a *file* symbol path to
 * their findings, whose namespace is `null` by construction. The declaring
 * namespace is carried by the finding's subject instead, so the filter falls back
 * to `subject->toSymbolPath()->namespace` when the symbol path has none. That keeps
 * the per-occurrence declaration namespace authoritative even in a file that
 * declares multiple namespaces.
 *
 * A finding on the project aggregate has no namespace to compare. Its symbol
 * path carries the display value `(project)` in that field, and comparing it
 * let `exact: '(project)'` — or a regex broad enough to match it — drop every
 * project-level finding, the unbound-suppression audit's report about that
 * very pattern included. Such a finding always passes; with nothing to bind
 * to, the pattern is then reported as matching no declared namespace.
 */
final readonly class NamespaceExclusionFilter implements FindingFilterInterface
{
    public function __construct(
        private NamespaceMatcher $namespaceMatcher,
        private ChannelFileScope $fileScope,
    ) {}

    public function shouldInclude(Finding $finding): bool
    {
        if (!$this->fileScope->isFileScoped($finding->channel())) {
            return true;
        }

        if ($finding->symbolPath->getType() === SymbolType::Project) {
            return true;
        }

        $namespace = $finding->symbolPath->namespace
            ?? $finding->subject->toSymbolPath()->namespace
            ?? '';

        return $this->namespaceMatcher->matches($namespace) === null;
    }
}
