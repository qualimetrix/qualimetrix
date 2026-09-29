<?php

declare(strict_types=1);

namespace QmxFindingGate;

/** Side-local capture decisions for complete raw baseline source groups. */
final class BaselineEligibility
{
    /** @var array<string,array<string,array<string,bool>>> */
    private array $sides = [];

    /** @param array<array-key,mixed> $sources */
    public function supply(string $side, array $sources): void
    {
        if (!\in_array($side, ['candidate', 'reference'], true) || isset($this->sides[$side])) {
            throw new GateError('Baseline eligibility has an unknown or repeated capture side.');
        }
        $checked = [];
        foreach ($sources as $source => $groups) {
            if (!\is_string($source) || !str_starts_with($source, 'case:') || !\is_array($groups)) {
                throw new GateError('Baseline eligibility requires a named source invocation.');
            }
            foreach ($groups as $identity => $eligible) {
                if (!\is_string($identity) || !\is_bool($eligible)) {
                    throw new GateError('Baseline eligibility requires exact group identities and boolean decisions.');
                }
            }
            $checked[$source] = $groups;
        }
        $this->sides[$side] = $checked;
    }

    /** @param array<string,mixed> $rawRecord */
    public function eligible(string $side, string $source, array $rawRecord): bool
    {
        $identity = ReportRecords::identity('json', $rawRecord);
        if (!\array_key_exists($identity, $this->sides[$side][$source] ?? [])) {
            throw new GateError('The complete baseline source group has no product eligibility decision: ' . $source . ' / ' . $identity);
        }
        return $this->sides[$side][$source][$identity];
    }

    /** @return list<array<string,mixed>> */
    public static function validatedRecords(mixed $records): array
    {
        if (!\is_array($records) || !array_is_list($records)) {
            throw new GateError('A baseline source has no complete record list.');
        }
        foreach ($records as $record) {
            if (!\is_array($record) || array_is_list($record)) {
                throw new GateError('A baseline source contains an invalid record.');
            }
        }
        return $records;
    }

    /** @param list<array<string,mixed>> $records
     * @return array<string,list<int|float|null>>
     */
    public static function groups(array $records): array
    {
        $groups = [];
        foreach ($records as $record) {
            try {
                $identity = ReportRecords::identity('json', $record);
            } catch (GateError) {
                $channel = $record['channel'] ?? null;
                if (!\is_string($channel) || $channel === '') {
                    throw new GateError('A malformed baseline source record has no usable channel.');
                }
                $identity = DeclaredRecords::canonical(['channel' => $channel, 'invalidTransport' => $record]);
            }
            $value = $record['metricValue'] ?? null;
            if ($value !== null && !\is_int($value) && !\is_float($value)) {
                throw new GateError('A baseline source metric value is not numeric.');
            }
            $groups[$identity][] = $value;
        }
        return $groups;
    }

    /** @param list<array<string,mixed>> $records
     * @param list<string> $arguments
     *
     * @return array<string,bool>
     */
    public static function capture(string $treeRoot, string $directory, array $arguments, array $records): array
    {
        $groups = self::groups($records);
        if ($groups === []) {
            return [];
        }
        $input = Fs::temporaryDirectory('baseline-eligibility-') . '/groups.json';
        try {
            Fs::write($input, json_encode($groups, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
            $result = Process::run([
                \PHP_BINARY, __DIR__ . '/probe-baseline.php', $treeRoot, $directory,
                json_encode($arguments, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES), $input,
            ], $directory);
        } finally {
            Fs::removeRecursively(\dirname($input));
        }
        if ($result['exit'] !== 0) {
            throw new GateError('Product baseline eligibility probe failed: ' . $result['stderr']);
        }
        $decisions = json_decode($result['stdout'], true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($decisions) || array_keys($decisions) !== array_keys($groups)
            || array_filter($decisions, static fn(mixed $value): bool => !\is_bool($value)) !== []) {
            throw new GateError('Product baseline eligibility probe did not partition every raw source group.');
        }
        return $decisions;
    }
}
