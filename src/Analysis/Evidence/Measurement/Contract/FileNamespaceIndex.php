<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Contract;

use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolLevel;

/** Declared and lexical namespaces associated with each measured physical file. */
final readonly class FileNamespaceIndex
{
    public const string LEXICAL_NAMESPACE_ENTRY = 'internal.file-lexical-namespace';

    /** @param array<string, array{declared?: array<string, string>, lexical?: array<string, string>}> $files */
    private function __construct(private array $files) {}

    public static function fromRepository(?MetricRepositoryInterface $repository): self
    {
        if ($repository === null) {
            return new self([]);
        }
        $files = [];
        self::recordDeclared($repository, $files);
        self::recordLexical($repository, $files);

        return new self($files);
    }

    /** @param array<string, array{declared?: array<string, string>, lexical?: array<string, string>}> $files */
    private static function recordDeclared(MetricRepositoryInterface $repository, array &$files): void
    {
        foreach ([$repository->allDeclarations(), $repository->allLogicalClasses()] as $observations) {
            foreach ($observations as $info) {
                $namespace = $info->subject?->toSymbolPath()->namespace;
                if ($namespace !== null && $info->file !== null) {
                    $files[$info->file->value()]['declared'][$namespace] = $namespace;
                }
            }
        }
    }

    /** @param array<string, array{declared?: array<string, string>, lexical?: array<string, string>}> $files */
    private static function recordLexical(MetricRepositoryInterface $repository, array &$files): void
    {
        foreach ($repository->all(SymbolLevel::File) as $file) {
            if ($file->file === null) {
                continue;
            }
            foreach ($repository->get($file->symbolPath)->entries(self::LEXICAL_NAMESPACE_ENTRY) as $entry) {
                $namespace = $entry['namespace'] ?? null;
                if (\is_string($namespace)) {
                    $files[$file->file->value()]['lexical'][$namespace] = $namespace;
                }
            }
        }
    }

    /**
     * Empty means no declared namespace was measured for this file. Consumers
     * decide whether that absence belongs to the global namespace in their view.
     *
     * @return list<string>
     */
    public function namespacesOf(RelativePath $file): array
    {
        return array_values($this->files[$file->value()]['declared'] ?? []);
    }

    /** @return list<string> */
    public function lexicalNamespacesOf(RelativePath $file): array
    {
        return array_values($this->files[$file->value()]['lexical'] ?? []);
    }
}
