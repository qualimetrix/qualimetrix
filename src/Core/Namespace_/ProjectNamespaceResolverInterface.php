<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Namespace_;

/**
 * Interface for resolving project namespace membership.
 *
 * Used to determine if a namespace belongs to the analyzed project
 * (not an external dependency).
 */
interface ProjectNamespaceResolverInterface
{
    /**
     * Check if namespace belongs to the project (not external dependency).
     *
     * @param string $namespace Full namespace (e.g., "App\Service\UserService")
     *
     * @return bool True if namespace belongs to the project
     */
    public function isProjectNamespace(string $namespace): bool;

    /**
     * Get list of project namespace prefixes.
     *
     * @return list<string> Normalized prefixes without trailing backslash
     */
    public function getProjectPrefixes(): array;
}
