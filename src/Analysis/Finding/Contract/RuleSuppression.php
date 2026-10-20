<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract;

use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Pattern\PathPattern;

final readonly class RuleSuppression
{
    /**
     * @param list<PathPattern> $paths
     * @param list<NamespacePattern> $namespaces
     * @param array<string, list<NamespacePattern>> $namespaceChannels
     */
    public function __construct(
        public array $paths = [],
        public array $namespaces = [],
        public array $namespaceChannels = [],
    ) {}
}
