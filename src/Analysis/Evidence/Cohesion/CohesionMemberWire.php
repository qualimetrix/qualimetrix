<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Cohesion;

use LogicException;
use Qualimetrix\Core\Symbol\TraitComposition\Contract\TraitMethodSource;
use Qualimetrix\Core\Symbol\TraitComposition\Contract\TraitUseFacts;

/** Encodes declared members and trait composition inputs for a class or trait. */
final class CohesionMemberWire
{
    /**
     * @param array<string, TraitMethodSource> $declaredMethods
     * @param list<TraitUseFacts> $traitUses
     *
     * @return list<array<string, scalar>>
     */
    public static function rows(array $declaredMethods, array $traitUses, int $instancePropertyCount): array
    {
        $rows = [];
        foreach ($declaredMethods as $source) {
            $rows[] = ['tag' => 'declaredMethod', 'name' => $source->originalMethod, 'visibility' => $source->visibility, 'abstract' => $source->isAbstract];
        }
        foreach (CohesionTraitUses::rows($traitUses) as $row) {
            $rows[] = $row;
        }
        $rows[] = ['tag' => 'instancePropertyCount', 'count' => $instancePropertyCount];

        return $rows;
    }

    /** @param array<string, scalar> $row */
    public static function addRow(LcomClassData $data, array $row): void
    {
        switch ($row['tag'] ?? null) {
            case 'declaredMethod':
                self::addDeclaredMethod($data, $row);
                break;
            case 'instancePropertyCount':
                self::addInstancePropertyCount($data, $row);
                break;
            default:
                throw new LogicException('Unknown LCOM fact row');
        }
    }

    /** @param array<string, scalar> $row */
    private static function addDeclaredMethod(LcomClassData $data, array $row): void
    {
        $abstract = $row['abstract'] ?? null;
        if (!\is_bool($abstract)) {
            throw new LogicException('LCOM declared method fact must contain a boolean abstract flag');
        }
        $data->declaration()->declareMethod((string) $row['name'], self::visibility($row['visibility'] ?? null), $abstract);
    }

    /** @param array<string, scalar> $row */
    private static function addInstancePropertyCount(LcomClassData $data, array $row): void
    {
        $count = $row['count'] ?? null;
        if (!\is_int($count) || $count < 0) {
            throw new LogicException('LCOM instance property count fact must be a non-negative integer');
        }
        $data->declaration()->incrementInstancePropertyCount($count);
    }

    /** @return 'public'|'protected'|'private' */
    private static function visibility(mixed $value): string
    {
        return match ($value) {
            'public' => 'public',
            'protected' => 'protected',
            'private' => 'private',
            default => throw new LogicException('Invalid Cohesion method visibility'),
        };
    }
}
