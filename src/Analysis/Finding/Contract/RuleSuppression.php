<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract;

use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ConfiguredValueWriters;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Pattern\PathPattern;

final readonly class RuleSuppression
{
    /**
     * @param list<PathPattern> $paths
     * @param list<NamespacePattern> $namespaces
     * @param array<string, list<NamespacePattern>> $namespaceChannels
     * @param ConfiguredValueWriters|null $pathWriters writers of the whole `suppress_paths` list; null means unknown
     * @param ConfiguredValueWriters|null $namespaceWriters writers of the whole `suppress_namespaces` list; null means unknown
     * @param array<string, ConfiguredValueWriters> $namespaceChannelWriters writers of each channel's entry, by selector
     */
    public function __construct(
        public array $paths = [],
        public array $namespaces = [],
        public array $namespaceChannels = [],
        public ?ConfiguredValueWriters $pathWriters = null,
        public ?ConfiguredValueWriters $namespaceWriters = null,
        public array $namespaceChannelWriters = [],
    ) {}
}
