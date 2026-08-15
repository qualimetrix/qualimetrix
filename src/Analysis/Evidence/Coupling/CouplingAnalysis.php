<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling;

use InvalidArgumentException;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
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
            throw new InvalidArgumentException('coupling must be an associative map.');
        }

        return $contribution;
    }

    /** @return list<string> */
    private function validatedPrefixes(mixed $prefixes): array
    {
        if (!\is_array($prefixes) || !array_is_list($prefixes)) {
            throw new InvalidArgumentException('coupling.framework_namespaces must be a list.');
        }

        foreach ($prefixes as $prefix) {
            if (!\is_string($prefix)) {
                throw new InvalidArgumentException('coupling.framework_namespaces entries must be strings.');
            }
        }

        return $prefixes;
    }

    public function isFramework(string $fqcn): bool
    {
        foreach ($this->frameworkNamespaces as $prefix) {
            if (str_starts_with($fqcn, $prefix . '\\') || $fqcn === $prefix) {
                return true;
            }
        }

        return false;
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
