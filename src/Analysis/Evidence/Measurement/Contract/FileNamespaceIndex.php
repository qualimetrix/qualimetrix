<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Contract;

use Qualimetrix\Core\Path\RelativePath;

/** Declared namespaces associated with each measured physical file. */
final readonly class FileNamespaceIndex
{
    /** @param array<string, list<string>> $namespaces */
    private function __construct(private array $namespaces) {}

    public static function fromRepository(?MetricRepositoryInterface $repository): self
    {
        if ($repository === null) {
            return new self([]);
        }
        $map = [];

        foreach ([$repository->allDeclarations(), $repository->allLogicalClasses()] as $observations) {
            foreach ($observations as $info) {
                $namespace = $info->subject?->toSymbolPath()->namespace;
                if ($namespace !== null && $info->file !== null) {
                    $map[$info->file->value()][$namespace] = $namespace;
                }
            }
        }

        return new self(array_map(static fn(array $namespaces): array => array_values($namespaces), $map));
    }

    /**
     * Empty means no declared namespace was measured for this file. Consumers
     * decide whether that absence belongs to the global namespace in their view.
     *
     * @return list<string>
     */
    public function namespacesOf(RelativePath $file): array
    {
        return $this->namespaces[$file->value()] ?? [];
    }
}
