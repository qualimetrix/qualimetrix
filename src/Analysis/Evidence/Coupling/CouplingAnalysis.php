<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling;

use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Evidence\Coupling\Contract\Configuration\CouplingConfiguratorInterface;

final class CouplingAnalysis implements CouplingConfiguratorInterface
{
    /** @var list<string> */
    private array $frameworkNamespaces = [];

    public function resolve(ConfigurationDocument $document): array
    {
        return $this->frameworkNamespacesFrom($document);
    }

    public function replace(array $frameworkNamespaces): void
    {
        $this->frameworkNamespaces = $frameworkNamespaces;
    }

    /** @return list<string> */
    private function frameworkNamespacesFrom(ConfigurationDocument $document): array
    {
        $frameworkNamespaces = [];

        foreach ($document->contributions('coupling') as $contribution) {
            $frameworkNamespaces = $this->replacementPrefixes($contribution, $frameworkNamespaces);
        }

        return $frameworkNamespaces;
    }

    /**
     * @param list<string> $currentPrefixes
     *
     * @return list<string>
     */
    private function replacementPrefixes(mixed $contribution, array $currentPrefixes): array
    {
        $coupling = $this->couplingContribution($contribution);

        return \array_key_exists('frameworkNamespaces', $coupling)
            ? $this->validatedPrefixes($coupling['frameworkNamespaces'])
            : $currentPrefixes;
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

    /** @return list<string> */
    private function validatedPrefixes(mixed $prefixes): array
    {
        if (!\is_array($prefixes) || !array_is_list($prefixes)) {
            throw ConfigurationRefusal::aboutResolvedInput(
                'Invalid value for "' . ConfigSchema::COUPLING_FRAMEWORK_NAMESPACES . '": expected a list of namespace prefixes.',
                ConfigSchema::COUPLING_FRAMEWORK_NAMESPACES,
            );
        }

        foreach ($prefixes as $prefix) {
            if (!\is_string($prefix)) {
                throw ConfigurationRefusal::aboutResolvedInput(
                    'Invalid entry in "' . ConfigSchema::COUPLING_FRAMEWORK_NAMESPACES . '": every entry must be a namespace prefix string.',
                    ConfigSchema::COUPLING_FRAMEWORK_NAMESPACES,
                );
            }
        }

        return $prefixes;
    }

    public function isFramework(string $fqcn): bool
    {
        foreach ($this->frameworkNamespaces as $prefix) {
            if (self::covers($prefix, $fqcn)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The declared prefixes that no name in $fqcns falls under.
     *
     * A prefix that binds nothing changes no metric: every class stays in
     * `coupling.cbo-app` and `coupling.ce-framework` stays zero, which is
     * exactly the state the author wrote the prefix to leave. Answering here
     * rather than in the caller keeps one home for the matching rule — the
     * caller would otherwise re-spell {@see isFramework()}'s comparison and
     * the two could disagree about, say, a leading backslash.
     *
     * Prefixes are compared verbatim, without normalisation: `\Symfony` never
     * matches anything {@see isFramework()} is asked about either, so it is a
     * genuinely unbound prefix rather than a spelling this method should
     * repair.
     *
     * @param iterable<string> $fqcns The names the run actually classified
     *
     * @return list<string> In declaration order; empty when every prefix bound
     */
    public function unboundPrefixes(iterable $fqcns): array
    {
        $unbound = array_values(array_unique($this->frameworkNamespaces));

        foreach ($fqcns as $fqcn) {
            $unbound = array_values(array_filter(
                $unbound,
                static fn(string $prefix): bool => !self::covers($prefix, $fqcn),
            ));

            if ($unbound === []) {
                return [];
            }
        }

        return $unbound;
    }

    private static function covers(string $prefix, string $fqcn): bool
    {
        return str_starts_with($fqcn, $prefix . '\\') || $fqcn === $prefix;
    }

    public function isFrameworkNamespace(?string $namespace): bool
    {
        return $namespace !== null && $namespace !== '' && $this->isFramework($namespace);
    }

    public function isEmpty(): bool
    {
        return $this->frameworkNamespaces === [];
    }
}
