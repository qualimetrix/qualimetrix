<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Document;

use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Document\ConfigurationDiagnostic;
use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedList;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedMap;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedOpaque;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\MergePolicy;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;

/**
 * Phase 2: folds one layer's read value over everything below it, by the
 * policy each node declares. An absent value on either side leaves the other
 * standing — that is all `~` means once phase 1 has dropped it.
 */
final class LayerMerge
{
    /** @var list<ConfigurationDiagnostic> */
    private array $diagnostics = [];

    public function merge(NodeSchema $schema, ?ResolvedValueInterface $lower, ?ResolvedValueInterface $upper): ?ResolvedValueInterface
    {
        if ($upper === null || $lower === null) {
            return $upper ?? $lower;
        }

        return match ($schema->policy) {
            MergePolicy::LastWriterWins => $upper,
            MergePolicy::DeepMerge, MergePolicy::ByName => $this->mergeMaps($schema, self::map($lower), self::map($upper)),
            MergePolicy::Replace => $this->replace($schema, self::list($lower), self::list($upper)),
            MergePolicy::Accumulate => self::accumulate(self::list($lower), self::list($upper)),
            MergePolicy::PerLayer => new ResolvedOpaque([...self::opaque($lower)->contributions(), ...self::opaque($upper)->contributions()]),
        };
    }

    /** @return list<ConfigurationDiagnostic> */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    private function mergeMaps(NodeSchema $schema, ResolvedMap $lower, ResolvedMap $upper): ResolvedMap
    {
        $entries = $lower->entries();

        foreach ($upper->entries() as $key => $value) {
            $entrySchema = $schema->policy === MergePolicy::ByName
                ? $schema->element()
                : $schema->fields()[$key] ?? NodeSchema::opaque();

            $entries[$key] = $this->merge($entrySchema, $entries[$key] ?? null, $value) ?? $value;
        }

        return new ResolvedMap($entries, [...$lower->contributors(), ...$upper->contributors()]);
    }

    private function replace(NodeSchema $schema, ResolvedList $lower, ResolvedList $upper): ResolvedList
    {
        $notice = $schema->emptyOverrideNotice();

        if ($notice !== null && $upper->items() === [] && $lower->items() !== []) {
            $upperWriter = $upper->contributors()[0];
            $lowerWriter = $lower->contributors()[\count($lower->contributors()) - 1];

            $this->diagnostics[] = new ConfigurationDiagnostic(
                \sprintf(
                    '%s is written empty in %s and replaces the list %s wrote. %s',
                    self::named($upperWriter),
                    $upperWriter->origin->describe(),
                    $lowerWriter->origin->describe(),
                    $notice,
                ),
                [$lowerWriter, $upperWriter],
            );
        }

        return $upper;
    }

    private static function accumulate(ResolvedList $lower, ResolvedList $upper): ResolvedList
    {
        $items = $lower->items();
        $seen = [];
        foreach ($items as $item) {
            $seen[serialize($item->plain())] = true;
        }

        foreach ($upper->items() as $item) {
            $key = serialize($item->plain());
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $items[] = $item;
            }
        }

        return new ResolvedList($items, [...$lower->contributors(), ...$upper->contributors()]);
    }

    private static function named(Provenance $writer): string
    {
        return $writer->path === null ? 'The list' : \sprintf('"%s"', $writer->displayPath());
    }

    private static function map(ResolvedValueInterface $value): ResolvedMap
    {
        return $value instanceof ResolvedMap ? $value : throw self::mismatch($value);
    }

    private static function list(ResolvedValueInterface $value): ResolvedList
    {
        return $value instanceof ResolvedList ? $value : throw self::mismatch($value);
    }

    private static function opaque(ResolvedValueInterface $value): ResolvedOpaque
    {
        return $value instanceof ResolvedOpaque ? $value : throw self::mismatch($value);
    }

    private static function mismatch(ResolvedValueInterface $value): LogicException
    {
        return new LogicException(\sprintf('Phase 1 produced a %s where the schema declares another node kind.', $value::class));
    }
}
