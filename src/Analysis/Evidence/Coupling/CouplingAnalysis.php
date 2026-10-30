<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling;

use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Evidence\Coupling\Configuration\CouplingSection;
use Qualimetrix\Analysis\Evidence\Coupling\Configuration\FrameworkNamespaceSelectorParser;
use Qualimetrix\Analysis\Evidence\Coupling\Contract\Configuration\CouplingConfiguratorInterface;
use Qualimetrix\Analysis\Evidence\Coupling\Contract\Configuration\FrameworkNamespaces;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ConfiguredValueWriters;
use Qualimetrix\Core\Pattern\NamespaceMatcher;
use Qualimetrix\Core\Pattern\NamespacePattern;

final class CouplingAnalysis implements CouplingConfiguratorInterface
{
    /** @var list<NamespacePattern> */
    private array $frameworkNamespaces = [];

    private ?ConfiguredValueWriters $frameworkWriters = null;

    private NamespaceMatcher $frameworkMatcher;

    public function __construct()
    {
        $this->frameworkMatcher = new NamespaceMatcher([]);
    }

    public function resolve(ConfigurationDocument $document): FrameworkNamespaces
    {
        $selectors = $document->resolved()->get(CouplingSection::KEY, 'framework_namespaces');
        if ($selectors === null) {
            return new FrameworkNamespaces();
        }

        return FrameworkNamespaceSelectorParser::parseDeclared($selectors);
    }

    public function replace(FrameworkNamespaces $frameworkNamespaces): void
    {
        $this->frameworkNamespaces = $frameworkNamespaces->patterns;
        $this->frameworkWriters = $frameworkNamespaces->writers;
        $this->frameworkMatcher = new NamespaceMatcher($frameworkNamespaces->patterns);
    }

    /** The sources that wrote the selector list; null when the list was not built from a document. */
    public function frameworkWriters(): ?ConfiguredValueWriters
    {
        return $this->frameworkWriters;
    }

    public function isFramework(string $fqcn): bool
    {
        return $this->frameworkMatcher->matches($fqcn) !== null;
    }

    /**
     * The declared selectors that match no name in $fqcns.
     *
     * A selector that binds nothing changes no metric: every class stays in
     * `coupling.cbo-app` and `coupling.ce-framework` stays zero, which is
     * exactly the state the author wrote the selector to leave. Answering here
     * rather than in the caller keeps one home for the matching rule — the
     * caller would otherwise reconstruct the executable selector and the two
     * could disagree about its exact, subtree, or regex semantics.
     *
     * @param iterable<string> $fqcns The names the run actually classified
     *
     * @return list<NamespacePattern> In declaration order; empty when every selector bound
     */
    public function unboundSelectors(iterable $fqcns): array
    {
        $unbound = [];
        foreach ($this->frameworkNamespaces as $pattern) {
            $unbound[$pattern->definition->display()] = $pattern;
        }

        foreach ($fqcns as $fqcn) {
            $unbound = array_filter(
                $unbound,
                static fn(NamespacePattern $pattern): bool => !$pattern->matches($fqcn),
            );

            if ($unbound === []) {
                return [];
            }
        }

        return array_values($unbound);
    }

    public function isEmpty(): bool
    {
        return $this->frameworkNamespaces === [];
    }

}
