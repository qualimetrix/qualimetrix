<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Contract;

use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolLevel;

/** Declared and lexical namespaces associated with each measured physical file. */
final readonly class FileNamespaceIndex
{
    public const string LEXICAL_NAMESPACE_ENTRY = 'internal.file-lexical-namespace';

    /**
     * @param array<string, list<string>> $namespaces
     * @param array<string, list<string>> $lexicalNamespaces
     */
    private function __construct(private array $namespaces, private array $lexicalNamespaces) {}

    public static function fromRepository(?MetricRepositoryInterface $repository): self
    {
        if ($repository === null) {
            return new self([], []);
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

        $lexical = [];
        foreach ($repository->all(SymbolLevel::File) as $file) {
            if ($file->file === null) {
                continue;
            }
            foreach ($repository->get($file->symbolPath)->entries(self::LEXICAL_NAMESPACE_ENTRY) as $entry) {
                $namespace = $entry['namespace'] ?? null;
                if (\is_string($namespace)) {
                    $lexical[$file->file->value()][$namespace] = $namespace;
                }
            }
        }

        return new self(
            array_map(static fn(array $namespaces): array => array_values($namespaces), $map),
            array_map(static fn(array $namespaces): array => array_values($namespaces), $lexical),
        );
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

    /** @return list<string> */
    public function lexicalNamespacesOf(RelativePath $file): array
    {
        return $this->lexicalNamespaces[$file->value()] ?? [];
    }
}
