<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\SuppressionBinding;

use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ConfiguredValueWriters;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Pattern\PathPattern;

/**
 * Who wrote each global `suppress_paths` and `suppress_namespaces` value.
 *
 * A value this does not know is judged and worded without a writer: the
 * sources are evidence about a value, never a precondition for judging it.
 */
final readonly class GlobalValueWriters
{
    /**
     * @param array<string, ConfiguredValueWriters> $paths keyed by the pattern's display
     * @param array<string, ConfiguredValueWriters> $namespaces keyed by the pattern's display
     */
    public function __construct(private array $paths = [], private array $namespaces = []) {}

    public function forPath(PathPattern $pattern): ?ConfiguredValueWriters
    {
        return $this->paths[$pattern->definition->display()] ?? null;
    }

    public function forNamespace(NamespacePattern $pattern): ?ConfiguredValueWriters
    {
        return $this->namespaces[$pattern->definition->display()] ?? null;
    }
}
