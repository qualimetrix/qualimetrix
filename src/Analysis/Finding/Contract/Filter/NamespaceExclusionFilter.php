<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Filter;

use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Core\Pattern\NamespaceMatcher;

/**
 * Suppresses findings whose declared namespace matches configured exclusion patterns.
 *
 * Project-scoped channels are exempt through {@see ChannelFileScope}.
 * A cycle or declaration diagnostic concerns the project, even when it has
 * an example location. A layer violation instead belongs to its source
 * declaration and follows that source's path and namespace exclusions.
 *
 * The finding transport can carry a file symbol with a declaration subject.
 * FindingNamespace retains that subject namespace when the symbol has none.
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

        $namespace = FindingNamespace::declared($finding);

        if ($namespace === null) {
            return true;
        }

        return $this->namespaceMatcher->matches($namespace) === null;
    }
}
