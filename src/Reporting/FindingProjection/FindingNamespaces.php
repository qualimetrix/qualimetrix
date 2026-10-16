<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\FindingProjection;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Core\Symbol\SymbolType;

/** Namespace attribution shared by report selection, grouping and records. */
final readonly class FindingNamespaces
{
    /** @return list<string> */
    public static function of(Finding $finding, FileNamespaceIndex $index): array
    {
        $subject = $finding->subject->toSymbolPath();
        if ($subject->getType() === SymbolType::Project) {
            return [];
        }
        if ($subject->getType() !== SymbolType::File) {
            return $subject->namespace === null ? [] : [$subject->namespace];
        }
        $namespaces = $finding->location->file === null ? [] : $index->namespacesOf($finding->location->file);
        sort($namespaces, \SORT_STRING);

        return $namespaces !== [] ? $namespaces : [''];
    }

    public static function group(Finding $finding, FileNamespaceIndex $index): string
    {
        $namespaces = self::of($finding, $index);

        return $namespaces === [] ? '[project]' : implode(', ', array_map(
            static fn(string $namespace): string => $namespace === '' ? '(global)' : $namespace,
            $namespaces,
        ));
    }
}
