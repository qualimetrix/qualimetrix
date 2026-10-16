<?php

declare(strict_types=1);

namespace QmxFindingGate;

/** Pairing never collapses repeated declarations into an associative map. */
final class MetricsRecords
{
    /**
     * @param list<array<string,mixed>> $candidate
     * @param list<array<string,mixed>> $reference
     *
     * @return array{pairs:list<array{candidate:array<string,mixed>,reference:array<string,mixed>,key:string}>,introduced:list<array<string,mixed>>,withdrawn:list<array<string,mixed>>}
     */
    public static function pair(array $candidate, array $reference): array
    {
        $subject = self::publishSubject($candidate) && self::publishSubject($reference);
        $left = self::groups($candidate, $subject);
        $right = self::groups($reference, $subject);
        $pairs = [];
        $introduced = [];
        $withdrawn = [];
        foreach (array_keys($left + $right) as $key) {
            $a = $left[$key] ?? [];
            $b = $right[$key] ?? [];
            if (\count($a) === 1 && \count($b) === 1) {
                $pairs[] = ['candidate' => $a[0], 'reference' => $b[0], 'key' => $key];
                continue;
            }
            foreach ($a as $record) {
                $at = array_search($record, $b, true);
                if ($at === false) {
                    $introduced[] = $record;
                } else {
                    $pairs[] = ['candidate' => $record, 'reference' => $b[$at], 'key' => DeclaredRecords::canonical($record)];
                    unset($b[$at]);
                }
            }
            array_push($withdrawn, ...array_values($b));
        }
        return ['pairs' => $pairs, 'introduced' => $introduced, 'withdrawn' => $withdrawn];
    }

    /** @param list<array<string,mixed>> $records */
    private static function publishSubject(array $records): bool
    {
        if ($records === []) {
            return false;
        }
        foreach ($records as $record) {
            if (!\is_string($record['subject'] ?? null) || $record['subject'] === '') {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string,mixed> $record
     */
    public static function key(array $record, bool $subject): string
    {
        if ($subject) {
            if (!\is_string($record['subject'] ?? null) || $record['subject'] === '') {
                throw new GateError('A metric pair requires its published subject.');
            }
            return DeclaredRecords::canonical(['subject' => $record['subject']]);
        }
        if (!\is_string($record['type'] ?? null) || !\is_string($record['name'] ?? null)) {
            throw new GateError('A metric pair requires published type and name strings.');
        }
        return DeclaredRecords::canonical(['type' => $record['type'], 'name' => $record['name']]);
    }

    /** @param array<string,mixed> $record */
    public static function level(array $record): string
    {
        return match ($record['type'] ?? null) {
            'method', 'function' => 'callable',
            'file', 'project', 'namespace', 'class' => $record['type'],
            default => throw new GateError('Unknown published metric record type.'),
        };
    }

    /**
     * @param list<array<string,mixed>> $records
     *
     * @return array<string,list<array<string,mixed>>>
     */
    private static function groups(array $records, bool $subject): array
    {
        $groups = [];
        foreach ($records as $record) {
            $groups[self::key($record, $subject)][] = $record;
        }
        return $groups;
    }
}
