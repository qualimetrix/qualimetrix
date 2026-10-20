<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Cohesion;

use LogicException;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\TraitUseAdaptation\Alias;
use PhpParser\Node\Stmt\TraitUseAdaptation\Precedence;
use Qualimetrix\Core\Ast\ResolvedName;
use Qualimetrix\Core\Symbol\TraitComposition\Contract\TraitUseFacts;

/** Converts one resolved trait-use statement into worker-safe composition facts. */
final class CohesionTraitUses
{
    /**
     * @param list<TraitUseFacts> $uses
     *
     * @return list<array<string, scalar>>
     */
    public static function rows(array $uses): array
    {
        $rows = [];
        foreach ($uses as $group => $use) {
            foreach ($use->traits as $trait) {
                $rows[] = ['tag' => 'trait', 'group' => $group, 'name' => $trait];
            }
            foreach ($use->aliases as $alias) {
                $rows[] = ['tag' => 'alias', 'group' => $group, 'trait' => $alias['trait'] ?? '', 'method' => $alias['method'], 'alias' => $alias['alias'] ?? '', 'visibility' => $alias['visibility'] ?? ''];
            }
            foreach ($use->precedences as $precedence) {
                foreach ($precedence['insteadOf'] as $excluded) {
                    $rows[] = ['tag' => 'precedence', 'group' => $group, 'trait' => $precedence['trait'], 'method' => $precedence['method'], 'excluded' => $excluded];
                }
            }
        }

        return $rows;
    }

    /**
     * @param list<array<string, scalar>> $rows
     *
     * @return list<TraitUseFacts>
     */
    public static function fromRows(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $group = $row['group'] ?? null;
            if (!\is_int($group) || $group < 0) {
                throw new LogicException('Cohesion trait fact group must be a non-negative integer');
            }
            $groups[$group][] = $row;
        }

        $uses = [];
        foreach ($groups as $groupRows) {
            $uses[] = self::fromGroupRows($groupRows);
        }

        return $uses;
    }

    /**
     * @param list<array<string, scalar>> $rows
     */
    private static function fromGroupRows(array $rows): TraitUseFacts
    {
        $traits = $aliases = $precedences = [];
        foreach ($rows as $row) {
            switch ($row['tag']) {
                case 'trait':
                    $traits[] = (string) $row['name'];
                    break;
                case 'alias':
                    $aliases[] = self::aliasFromRow($row);
                    break;
                case 'precedence':
                    $precedences[] = [
                        'trait' => (string) $row['trait'],
                        'method' => (string) $row['method'],
                        'insteadOf' => [(string) $row['excluded']],
                    ];
                    break;
                default:
                    throw new LogicException('Unknown Cohesion trait fact row');
            }
        }

        return new TraitUseFacts($traits, $aliases, $precedences);
    }

    /**
     * @param array<string, scalar> $row
     *
     * @return array{trait: ?string, method: string, alias: ?string, visibility: 'public'|'protected'|'private'|null}
     */
    private static function aliasFromRow(array $row): array
    {
        return [
            'trait' => $row['trait'] === '' ? null : (string) $row['trait'],
            'method' => (string) $row['method'],
            'alias' => $row['alias'] === '' ? null : (string) $row['alias'],
            'visibility' => $row['visibility'] === '' ? null : self::visibility($row['visibility']),
        ];
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

    public static function fromNode(TraitUse $use): TraitUseFacts
    {
        $traits = [];
        foreach ($use->traits as $trait) {
            $traits[] = ResolvedName::className($trait) ?? $trait->toString();
        }
        $aliases = $precedences = [];
        foreach ($use->adaptations as $adaptation) {
            if ($adaptation instanceof Alias) {
                $aliases[] = self::aliasFromNode($adaptation);
            } elseif ($adaptation instanceof Precedence) {
                $precedences[] = self::precedenceFromNode($adaptation);
            }
        }

        return new TraitUseFacts($traits, $aliases, $precedences);
    }

    /** @return array{trait: ?string, method: string, alias: ?string, visibility: 'public'|'protected'|'private'|null} */
    private static function aliasFromNode(Alias $adaptation): array
    {
        return [
            'trait' => $adaptation->trait === null ? null : (ResolvedName::className($adaptation->trait) ?? $adaptation->trait->toString()),
            'method' => $adaptation->method->toString(),
            'alias' => $adaptation->newName?->toString(),
            'visibility' => match ($adaptation->newModifier) {
                Class_::MODIFIER_PUBLIC => 'public',
                Class_::MODIFIER_PROTECTED => 'protected',
                Class_::MODIFIER_PRIVATE => 'private',
                default => null,
            },
        ];
    }

    /** @return array{trait: string, method: string, insteadOf: list<string>} */
    private static function precedenceFromNode(Precedence $adaptation): array
    {
        if ($adaptation->trait === null) {
            throw new LogicException('Trait precedence requires a qualified source');
        }
        $insteadOf = [];
        foreach ($adaptation->insteadof as $excluded) {
            $insteadOf[] = ResolvedName::className($excluded) ?? $excluded->toString();
        }

        return [
            'trait' => ResolvedName::className($adaptation->trait) ?? $adaptation->trait->toString(),
            'method' => $adaptation->method->toString(),
            'insteadOf' => $insteadOf,
        ];
    }
}
