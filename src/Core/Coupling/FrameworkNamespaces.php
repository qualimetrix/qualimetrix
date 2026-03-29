<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Coupling;

/**
 * Value object holding configured framework namespace prefixes.
 *
 * Used to distinguish framework dependencies (structural, cannot be eliminated
 * without changing framework) from application dependencies (architectural,
 * should be minimized).
 *
 * Matching is boundary-aware: prefix "Psr" matches "Psr\Log\LoggerInterface"
 * but NOT "PsrExtended\Custom".
 */
final readonly class FrameworkNamespaces
{
    /**
     * @param list<string> $prefixes Framework namespace prefixes (e.g., ['Symfony', 'PhpParser', 'Psr'])
     */
    public function __construct(
        public array $prefixes = [],
    ) {}

    /**
     * Checks if a fully qualified class name belongs to a framework namespace.
     *
     * Uses boundary-aware prefix matching: the FQCN must start with the prefix
     * followed by a backslash, or be exactly the prefix (for single-segment namespaces).
     */
    public function isFramework(string $fqcn): bool
    {
        foreach ($this->prefixes as $prefix) {
            if (str_starts_with($fqcn, $prefix . '\\')) {
                return true;
            }

            // Exact match for global classes with the same name as prefix (unlikely but correct)
            if ($fqcn === $prefix) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if a namespace belongs to a framework.
     *
     * Same matching logic as isFramework() but intended for namespace strings.
     */
    public function isFrameworkNamespace(?string $namespace): bool
    {
        if ($namespace === null || $namespace === '') {
            return false;
        }

        return $this->isFramework($namespace);
    }

    /**
     * Returns true if no framework namespaces are configured.
     */
    public function isEmpty(): bool
    {
        return $this->prefixes === [];
    }
}
