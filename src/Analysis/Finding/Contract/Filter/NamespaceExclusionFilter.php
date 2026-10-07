<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Filter;

use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Core\Pattern\NamespaceMatcher;
use Qualimetrix\Core\Symbol\SymbolType;

/**
 * Suppresses findings whose symbol namespace matches configured exclusion patterns.
 *
 * Project-scoped channels are exempt through {@see ChannelFileScope}.
 * A cycle or declaration diagnostic concerns the project, even when it has
 * an example location. A layer violation instead belongs to its source
 * declaration and follows that source's path and namespace exclusions.
 *
 * Occurrence-style rules (code-smell and security) attach a *file* symbol path to
 * their findings, whose namespace is `null` by construction. The declaring
 * namespace is carried by the finding's subject instead, so the filter falls back
 * to `subject->toSymbolPath()->namespace` when the symbol path has none. That keeps
 * the per-occurrence declaration namespace authoritative even in a file that
 * declares multiple namespaces.
 * A file aggregate has no declaration namespace. When both symbol and subject
 * namespaces are null, no namespace pattern can suppress it; a declaration in
 * the global namespace carries the distinct value `''` and is compared.
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
            ?? $finding->subject->toSymbolPath()->namespace;

        if ($namespace === null) {
            return true;
        }

        return $this->namespaceMatcher->matches($namespace) === null;
    }
}
