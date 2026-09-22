<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling;

use InvalidArgumentException;
use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusedPosition;
use Qualimetrix\Analysis\Evidence\Coupling\Contract\Configuration\CouplingConfiguratorInterface;
use Qualimetrix\Core\Pattern\NamespaceMatcher;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;

/**
 * @qmx-threshold coupling.instability 0.81 -- Coupling configuration owns selector decoding and the executable framework matcher. Raw instability 0.80 is accepted after explicit selectors; further outward growth is reported.
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
        $frameworkNamespaces = [];

        foreach ($document->contributions('coupling') as $contribution) {
            $frameworkNamespaces = $this->replacementSelectors($contribution, $frameworkNamespaces);
        }

        return $frameworkNamespaces;
    }

    /**
     * @param list<NamespacePattern> $currentSelectors
     *
     * @return list<NamespacePattern>
     */
    private function replacementSelectors(mixed $contribution, array $currentSelectors): array
    {
        $coupling = $this->couplingContribution($contribution);

        return \array_key_exists('frameworkNamespaces', $coupling)
            ? $this->validatedSelectors($coupling['frameworkNamespaces'])
            : $currentSelectors;
    }

    /** @return array<string, mixed> */
    private function couplingContribution(mixed $contribution): array
    {
        if (!\is_array($contribution) || ($contribution !== [] && array_is_list($contribution))) {
            throw ConfigurationRefusal::aboutResolvedInput(
                'Invalid value for "' . ConfigSchema::COUPLING . '": expected an associative map of coupling settings.',
                ConfigSchema::COUPLING,
            );
        }

        return $contribution;
    }

    /** @return list<NamespacePattern> */
    private function validatedSelectors(mixed $selectors): array
    {
        if (!\is_array($selectors) || !array_is_list($selectors)) {
            throw ConfigurationRefusal::aboutResolvedInput(
                'Invalid value for "' . ConfigSchema::COUPLING_FRAMEWORK_NAMESPACES . '": expected a list of explicit namespace selector mappings.',
                ConfigSchema::COUPLING_FRAMEWORK_NAMESPACES,
            );
        }

        $patterns = [];
        foreach ($selectors as $index => $selector) {
            $patterns[] = $this->namespacePattern($selector, $index);
        }

        try {
            new NamespaceMatcher($patterns);
        } catch (InvalidArgumentException $e) {
            throw ConfigurationRefusal::aboutResolvedInput(
                $e->getMessage(),
                ConfigSchema::COUPLING_FRAMEWORK_NAMESPACES,
                $e,
            );
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

    private function namespacePattern(mixed $selector, int $index): NamespacePattern
    {
        $position = RefusedPosition::open(
            [ConfigSchema::COUPLING, 'frameworkNamespaces', (string) $index],
            (string) $index,
        );

        if (!\is_array($selector) || \count($selector) !== 1) {
            throw ConfigurationRefusal::atResolvedKey(
                $position,
                'Framework namespace selectors must be one-entry mappings: {exact: value}, {subtree: value}, or {regex: value}; bare strings are not supported.',
                ConfigSchema::COUPLING_FRAMEWORK_NAMESPACES,
            );
        }

        $kind = array_key_first($selector);
        $value = \is_string($kind) ? $selector[$kind] : null;
        if (!\is_string($kind) || !\is_string($value) || $value === '') {
            throw ConfigurationRefusal::atResolvedKey(
                $position,
                'Framework namespace selectors must name exact, subtree, or regex with a non-empty string value.',
                ConfigSchema::COUPLING_FRAMEWORK_NAMESPACES,
            );
        }

        try {
            return new NamespacePattern(SelectorDefinition::fromKindAndValue($kind, $value));
        } catch (InvalidArgumentException $e) {
            throw ConfigurationRefusal::atResolvedKey($position, $e->getMessage(), ConfigSchema::COUPLING_FRAMEWORK_NAMESPACES, $e);
        }
    }
}
