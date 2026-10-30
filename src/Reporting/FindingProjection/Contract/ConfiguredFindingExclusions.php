<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\FindingProjection\Contract;

use Qualimetrix\Analysis\Finding\Contract\GlobalValueWriters;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Pattern\PathPattern;

final readonly class ConfiguredFindingExclusions
{
    /**
     * @param list<PathPattern> $suppressPaths
     * @param list<NamespacePattern> $suppressNamespaces
     */
    public function __construct(
        public array $suppressPaths = [],
        public array $suppressNamespaces = [],
        public GlobalValueWriters $writers = new GlobalValueWriters(),
    ) {}

    /**
     * The values given on the command line added, each written by its option.
     *
     * @param list<PathPattern> $paths
     * @param list<NamespacePattern> $namespaces
     */
    public function withCommandLine(array $paths, string $pathOption, array $namespaces, string $namespaceOption): self
    {
        return new self(
            self::uniquePatterns([...$this->suppressPaths, ...$paths]),
            self::uniquePatterns([...$this->suppressNamespaces, ...$namespaces]),
            $this->writers->with(GlobalValueWriters::commandLine($paths, $pathOption, $namespaces, $namespaceOption)),
        );
    }

    /**
     * @template T of PathPattern|NamespacePattern
     *
     * @param list<T> $patterns
     *
     * @return list<T>
     */
    private static function uniquePatterns(array $patterns): array
    {
        $unique = [];
        foreach ($patterns as $pattern) {
            $unique[$pattern->definition->display()] = $pattern;
        }

        return array_values($unique);
    }
}
