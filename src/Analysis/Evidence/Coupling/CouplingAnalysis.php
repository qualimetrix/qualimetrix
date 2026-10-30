<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling;

use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Evidence\Coupling\Configuration\CouplingSection;
use Qualimetrix\Analysis\Evidence\Coupling\Configuration\FrameworkNamespaceSelectorParser;
use Qualimetrix\Analysis\Evidence\Coupling\Contract\Configuration\CouplingConfiguratorInterface;
use Qualimetrix\Analysis\Evidence\Coupling\Contract\Configuration\FrameworkNamespaces;
use Qualimetrix\Core\Pattern\NamespaceMatcher;
use Qualimetrix\Core\Pattern\NamespacePattern;

final class CouplingAnalysis implements CouplingConfiguratorInterface
{
    private FrameworkNamespaces $framework;

    private NamespaceMatcher $frameworkMatcher;

    public function __construct()
    {
        $this->framework = new FrameworkNamespaces();
        $this->frameworkMatcher = new NamespaceMatcher([]);
    }

    public function resolve(ConfigurationDocument $document): FrameworkNamespaces
    {
        $selectors = $document->resolved()->get(CouplingSection::KEY, 'framework_namespaces');
        if ($selectors === null) {
            return new FrameworkNamespaces();
        }

        return new FrameworkNamespaces(FrameworkNamespaceSelectorParser::parseList($selectors), $document->writtenBy(CouplingSection::KEY, 'framework_namespaces'));
    }

    public function replace(FrameworkNamespaces $frameworkNamespaces): void
    {
        $this->framework = $frameworkNamespaces;
        $this->frameworkMatcher = new NamespaceMatcher($frameworkNamespaces->patterns);
    }

    /** The selectors in force, with the sources that wrote them. */
    public function framework(): FrameworkNamespaces
    {
        return $this->framework;
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
        foreach ($this->framework->patterns as $pattern) {
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
        return $this->framework->patterns === [];
    }
}
