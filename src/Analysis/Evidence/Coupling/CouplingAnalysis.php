<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling;

use InvalidArgumentException;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedListInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Evidence\Coupling\Configuration\CouplingSection;
use Qualimetrix\Analysis\Evidence\Coupling\Contract\Configuration\CouplingConfiguratorInterface;
use Qualimetrix\Core\Pattern\NamespaceMatcher;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;

/**
 * @qmx-threshold coupling.instability warning=0.85 -- The Coupling configuration-document adapter has Ca=2 and Ce=11 (I=0.84615); the next outward dependency, Ce=12 (I=0.85714), remains a warning.
 */
final class CouplingAnalysis implements CouplingConfiguratorInterface
{
    /** @var list<NamespacePattern> */
    private array $frameworkNamespaces = [];

    private NamespaceMatcher $frameworkMatcher;

    public function __construct()
    {
        $this->frameworkMatcher = new NamespaceMatcher([]);
    }

    public function resolve(ConfigurationDocument $document): array
    {
        return $this->frameworkNamespacesFrom($document);
    }

    public function replace(array $frameworkNamespaces): void
    {
        $this->frameworkNamespaces = $frameworkNamespaces;
        $this->frameworkMatcher = new NamespaceMatcher($frameworkNamespaces);
    }

    /** @return list<NamespacePattern> */
    private function frameworkNamespacesFrom(ConfigurationDocument $document): array
    {
        $selectors = $document->resolved()->get(CouplingSection::KEY, 'framework_namespaces');
        if ($selectors === null) {
            return [];
        }

        if (!$selectors instanceof ResolvedListInterface) {
            $selectors->refuse('Framework namespace selectors must be a list.');
        }

        return $this->validatedSelectors($selectors);
    }

    /** @return list<NamespacePattern> */
    private function validatedSelectors(ResolvedListInterface $selectors): array
    {
        $patterns = [];
        foreach ($selectors->items() as $selector) {
            $patterns[] = $this->namespacePattern($selector);
        }

        try {
            new NamespaceMatcher($patterns);
        } catch (InvalidArgumentException $e) {
            throw self::refusalFrom($selectors, $e);
        }

        return $patterns;
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

    public function isFrameworkNamespace(?string $namespace): bool
    {
        return $namespace !== null && $namespace !== '' && $this->isFramework($namespace);
    }

    public function isEmpty(): bool
    {
        return $this->frameworkNamespaces === [];
    }

    private function namespacePattern(ResolvedValueInterface $selector): NamespacePattern
    {
        $value = $selector->plain();

        if (!\is_array($value) || \count($value) !== 1) {
            $selector->refuse(
                'Framework namespace selectors must be one-entry mappings: {exact: value}, {subtree: value}, or {regex: value}; bare strings are not supported.',
            );
        }

        $kind = array_key_first($value);
        $pattern = \is_string($kind) ? $value[$kind] : null;
        if (!\is_string($kind) || !\is_string($pattern) || $pattern === '') {
            $selector->refuse(
                'Framework namespace selectors must name exact, subtree, or regex with a non-empty string value.',
            );
        }

        try {
            return new NamespacePattern(SelectorDefinition::fromKindAndValue($kind, $pattern));
        } catch (InvalidArgumentException $e) {
            throw self::refusalFrom($selector, $e);
        }
    }

    private static function refusalFrom(ResolvedValueInterface $value, InvalidArgumentException $cause): ConfigurationRefusal
    {
        $writers = $value->contributors();

        return ConfigurationRefusal::acrossLayers(
            array_map(static fn(Provenance $writer): ConfigurationOrigin => $writer->origin, $writers),
            $writers[\count($writers) - 1]->position(),
            $cause->getMessage(),
            $cause,
        );
    }
}
