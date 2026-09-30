<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Document;

use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Document\ConfigurationDiagnostic;
use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\MergePolicy;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Document\Resolved\ResolvedBareName;
use Qualimetrix\Analysis\Configuration\Document\Resolved\ResolvedList;
use Qualimetrix\Analysis\Configuration\Document\Resolved\ResolvedMap;
use Qualimetrix\Analysis\Configuration\Document\Resolved\ResolvedOpaque;
use Qualimetrix\Analysis\Configuration\Document\Resolved\ResolvedScalar;
use SplObjectStorage;

/**
 * Phase 2: folds one layer's read value over everything below it, by the
 * policy each node declares. An absent value on either side leaves the other
 * standing — that is all `~` means once phase 1 has dropped it. A named entry
 * written without a body is absent in the same sense, except that it stands
 * when no layer gave the name a body.
 */
final class LayerMerge
{
    /**
     * An empty list that replaced a non-empty one, with the layer that wrote
     * the non-empty one. Kept only while the empty list stands: a higher
     * layer that replaces it again decides the node, so what was said about
     * the lower fold would describe a value the run does not use.
     *
     * @var SplObjectStorage<ResolvedList, array{Provenance, Provenance, string}>
     */
    private SplObjectStorage $emptyOverrides;

    public function __construct()
    {
        $this->emptyOverrides = new SplObjectStorage();
    }

    public function merge(NodeSchema $schema, ?ResolvedValueInterface $lower, ?ResolvedValueInterface $upper): ?ResolvedValueInterface
    {
        if ($upper === null || $lower === null) {
            return $upper ?? $lower;
        }

        if ($upper instanceof ResolvedBareName || $lower instanceof ResolvedBareName) {
            return self::besideBareName($lower, $upper);
        }

        return $this->mergeBodies($schema, $lower, $upper);
    }

    /**
     * What the merged document says about itself: only the folds whose
     * result every layer above left standing.
     *
     * @return list<ConfigurationDiagnostic>
     */
    public function diagnostics(): array
    {
        $diagnostics = [];
        foreach ($this->emptyOverrides as $list) {
            [$upperWriter, $lowerWriter, $notice] = $this->emptyOverrides[$list];
            $diagnostics[] = new ConfigurationDiagnostic(
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

        return $diagnostics;
    }

    /** Two written bodies, folded by the node's policy. */
    private function mergeBodies(NodeSchema $schema, ResolvedValueInterface $lower, ResolvedValueInterface $upper): ResolvedValueInterface
    {
        return match ($schema->policy) {
            MergePolicy::LastWriterWins => self::lastWriterWins($lower, $upper),
            MergePolicy::DeepMerge, MergePolicy::ByName => $this->mergeMaps($schema, self::map($lower), self::map($upper)),
            MergePolicy::Replace => $this->replace($schema, self::list($lower), self::list($upper)),
            MergePolicy::Accumulate => self::accumulate(self::list($lower), self::list($upper)),
            MergePolicy::PerLayer => new ResolvedOpaque([...self::opaque($lower)->contributions(), ...self::opaque($upper)->contributions()]),
        };
    }

    private static function lastWriterWins(ResolvedValueInterface $lower, ResolvedValueInterface $upper): ResolvedScalar
    {
        if (!$lower instanceof ResolvedScalar || !$upper instanceof ResolvedScalar) {
            throw self::mismatch($upper);
        }

        return new ResolvedScalar($upper->value, $upper->provenance, [...$lower->writes(), ...$upper->writes()]);
    }

    /** A body on either side stands; a name written bare by both keeps every writer. */
    private static function besideBareName(ResolvedValueInterface $lower, ResolvedValueInterface $upper): ResolvedValueInterface
    {
        if ($upper instanceof ResolvedBareName) {
            return $lower instanceof ResolvedBareName ? $lower->writtenAgainBy($upper) : $lower;
        }

        return $upper;
    }

    private function mergeMaps(NodeSchema $schema, ResolvedMap $lower, ResolvedMap $upper): ResolvedMap
    {
        $entries = $lower->entries();

        foreach ($upper->entries() as $key => $value) {
            if ($schema->policy === MergePolicy::ByName) {
                $entrySchema = $schema->entryForName($key);
                if ($entrySchema === null) {
                    if (!$value instanceof ResolvedBareName || (isset($entries[$key]) && !$entries[$key] instanceof ResolvedBareName)) {
                        throw new LogicException(\sprintf('No schema is declared for named entry "%s".', $key));
                    }
                    $entries[$key] = $this->merge($schema, $entries[$key] ?? null, $value) ?? $value;
                    continue;
                }
            } else {
                $entrySchema = $schema->fields()[$key] ?? NodeSchema::opaque();
            }

            $entries[$key] = $this->merge($entrySchema, $entries[$key] ?? null, $value) ?? $value;
        }

        return new ResolvedMap($entries, [...$lower->contributors(), ...$upper->contributors()]);
    }

    private function replace(NodeSchema $schema, ResolvedList $lower, ResolvedList $upper): ResolvedList
    {
        $notice = $schema->emptyOverrideNotice();
        $replaced = isset($this->emptyOverrides[$lower]) ? $this->emptyOverrides[$lower] : null;
        unset($this->emptyOverrides[$lower]);

        $result = new ResolvedList($upper->items(), $upper->contributors(), [...$lower->writes(), ...$upper->writes()]);

        if ($notice === null || $upper->items() !== []) {
            return $result;
        }

        // An empty list over an empty one that already lifted a filter keeps
        // naming the layer whose filter was lifted.
        $lowerWriter = $lower->items() !== []
            ? $lower->contributors()[\count($lower->contributors()) - 1]
            : $replaced[1] ?? null;

        if ($lowerWriter !== null) {
            $this->emptyOverrides[$result] = [$upper->contributors()[0], $lowerWriter, $notice];
        }

        return $result;
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

        return new ResolvedList($items, [...$lower->contributors(), ...$upper->contributors()], [...$lower->writes(), ...$upper->writes()]);
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
