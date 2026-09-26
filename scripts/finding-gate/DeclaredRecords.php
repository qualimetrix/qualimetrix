<?php

declare(strict_types=1);

namespace QmxFindingGate;

use JsonException;

/**
 * Exact scalar selectors license measured complete records as a multiset.
 *
 * The comparator validates each record against its own report's schema; the
 * loader validates the declared selector and every derived record's intent.
 */
final class DeclaredRecords
{
    public const array COLUMNS = ['change', 'case', 'report', 'view', 'selector', 'reason'];
    public const array DERIVED_COLUMNS = ['change', 'case', 'report', 'view', 'record'];
    public const string INDEX = 'declared-records.tsv';
    public const string DERIVED = 'declared-records.derived.tsv';
    public const string WITHDRAWN = 'withdrawn';
    public const string INTRODUCED = 'introduced';
    public const array REPORTS = ['json', 'suppressed', 'metrics', 'directives'];
    private const int CANONICAL = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR;

    /** @var array<int, true> */
    private array $claimed = [];
    /** @var array<int, true> */
    private array $credited = [];

    /**
     * @param list<array{change:string,case:string,report:string,view:string,selector:string,reason:string}> $intents
     * @param list<array{change:string,case:string,report:string,view:string,record:string}> $derived
     * @param list<array<string, scalar|null>> $selectors
     */
    private function __construct(
        private readonly array $intents,
        private readonly array $derived,
        private readonly array $selectors,
        private readonly string $derivedText,
    ) {}

    public static function load(string $root): self
    {
        $intents = [];
        $selectors = [];

        foreach (DeclarationTable::rows($root, self::INDEX, self::COLUMNS) as $index => $row) {
            self::assertKind($row, self::INDEX, $index + 1);
            $selector = self::decoded($row['selector'], self::INDEX, $index + 1);
            foreach ($selector as $key => $value) {
                if (!\is_string($key) || $key === '' || (!\is_scalar($value) && $value !== null)) {
                    throw new GateError(self::INDEX . ' selectors require named scalar equality fields.');
                }
            }
            if ($row['report'] === 'metrics' && (!isset($selector['type'], $selector['name'])
                || !\is_string($selector['type']) || !\is_string($selector['name']))) {
                throw new GateError(self::INDEX . ' metrics selectors require exact type and name strings.');
            }
            foreach ($intents as $previous => $intent) {
                if ($intent['change'] === $row['change'] && $intent['report'] === $row['report'] && $intent['view'] === $row['view']
                    && ($intent['case'] === '*' || $row['case'] === '*' || $intent['case'] === $row['case'])
                    && self::overlap($selectors[$previous], $selector)) {
                    throw new GateError(\sprintf('%s row %d overlaps an earlier selector.', self::INDEX, $index + 1));
                }
            }
            /** @var array<string, scalar|null> $selector */
            $selectors[] = $selector;
            $intents[] = ['change' => $row['change'], 'case' => $row['case'], 'report' => $row['report'], 'view' => $row['view'], 'selector' => $row['selector'], 'reason' => $row['reason']];
        }

        $derived = [];
        foreach (DeclarationTable::rows($root, self::DERIVED, self::DERIVED_COLUMNS) as $index => $row) {
            self::assertKind($row, self::DERIVED, $index + 1);
            if ($row['case'] === '*') {
                throw new GateError(self::DERIVED . ' names concrete cases, never a wildcard.');
            }
            $record = self::decoded($row['record'], self::DERIVED, $index + 1);
            if (self::intentOf($intents, $selectors, $row['change'], $row['case'], $row['report'], $row['view'], $record) === null) {
                throw new GateError(\sprintf('%s row %d measures a record which no intent declares.', self::DERIVED, $index + 1));
            }
            $derived[] = ['change' => $row['change'], 'case' => $row['case'], 'report' => $row['report'], 'view' => $row['view'], 'record' => $row['record']];
        }
        $path = $root . '/' . self::DERIVED;

        return new self($intents, $derived, $selectors, is_file($path) ? Fs::read($path) : '');
    }

    /** @param array<string, mixed> $record */
    public static function canonical(array $record): string
    {
        return json_encode($record, self::CANONICAL);
    }

    public function count(): int
    {
        return \count($this->intents);
    }

    /** @return list<array{change:string,case:string,report:string,view:string,selector:string,reason:string}> */
    public function intents(string $report, string $view): array
    {
        ReportViews::assert($report, $view);
        return array_values(array_filter($this->intents, static fn(array $row): bool => $row['report'] === $report && $row['view'] === $view));
    }

    /** @return list<array{change:string,case:string,report:string,view:string,record:string}> */
    public function derived(string $report, string $view): array
    {
        ReportViews::assert($report, $view);
        return array_values(array_filter($this->derived, static fn(array $row): bool => $row['report'] === $report && $row['view'] === $view));
    }

    public function derivedText(): string
    {
        return $this->derivedText;
    }

    public function claim(string $change, string $case, string $report, string $view, string $record): bool
    {
        foreach ($this->derived as $index => $row) {
            if (!isset($this->claimed[$index]) && $row['change'] === $change && $row['case'] === $case
                && $row['report'] === $report && $row['view'] === $view && $row['record'] === $record) {
                $this->claimed[$index] = true;
                $intent = self::intentOf($this->intents, $this->selectors, $change, $case, $report, $view, self::decoded($record, self::DERIVED, $index + 1));
                if ($intent === null) {
                    throw new GateError('A derived record lost its declared intent.');
                }
                $this->credited[$intent] = true;
                return true;
            }
        }
        return false;
    }

    public function creditMeasurement(string $change, string $case, string $report, string $view, string $record): bool
    {
        $intent = self::intentOf($this->intents, $this->selectors, $change, $case, $report, $view, self::decoded($record, self::DERIVED, 1));
        if ($intent === null) {
            return false;
        }
        $this->credited[$intent] = true;
        return true;
    }

    /** @return list<array{scope:string,detail:string}> */
    public function staleIntents(): array
    {
        $stale = [];
        foreach ($this->intents as $index => $row) {
            if (!isset($this->credited[$index])) {
                $stale[] = ['scope' => self::INDEX, 'detail' => \sprintf('The %s %s/%s selector %s for %s', $row['change'], $row['report'], $row['view'], $row['selector'], $row['case'])];
            }
        }
        return $stale;
    }

    /** @return list<array{scope:string,detail:string}> */
    public function stale(): array
    {
        $stale = [];
        foreach ($this->derived as $index => $row) {
            if (!isset($this->claimed[$index])) {
                $stale[] = ['scope' => 'case:' . $row['case'] . '|' . $row['view'], 'detail' => \sprintf('The %s record %s', $row['change'], $row['record'])];
            }
        }
        foreach ($this->intents as $index => $row) {
            if (!isset($this->credited[$index])) {
                $stale[] = ['scope' => self::INDEX, 'detail' => \sprintf('The %s %s/%s selector %s for %s', $row['change'], $row['report'], $row['view'], $row['selector'], $row['case'])];
            }
        }
        return $stale;
    }

    /** @return array<string, mixed> */
    private static function decoded(string $text, string $file, int $number): array
    {
        try {
            $decoded = json_decode($text, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new GateError(\sprintf('%s row %d is not JSON (%s).', $file, $number, $error->getMessage()));
        }
        if (!\is_array($decoded) || array_is_list($decoded)) {
            throw new GateError(\sprintf('%s row %d is not a nonempty JSON object.', $file, $number));
        }
        if (self::canonical($decoded) !== $text) {
            throw new GateError(\sprintf('%s row %d is spelled otherwise than canonically.', $file, $number));
        }
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @param array<string,string> $row */
    private static function assertKind(array $row, string $file, int $number): void
    {
        DeclarationTable::oneOf($file, $number, 'change', $row['change'], [self::WITHDRAWN, self::INTRODUCED]);
        DeclarationTable::oneOf($file, $number, 'report', $row['report'], self::REPORTS);
        ReportViews::assert($row['report'], $row['view']);
    }

    /**
     * @param array<string,mixed> $first
     * @param array<string,mixed> $second
     */
    private static function overlap(array $first, array $second): bool
    {
        foreach (array_intersect(array_keys($first), array_keys($second)) as $key) {
            if ($first[$key] !== $second[$key]) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param list<array{change:string,case:string,report:string,view:string,selector:string,reason:string}> $intents
     * @param list<array<string,scalar|null>> $selectors
     * @param array<string,mixed> $record
     */
    private static function intentOf(array $intents, array $selectors, string $change, string $case, string $report, string $view, array $record): ?int
    {
        foreach ($intents as $index => $intent) {
            if ($intent['change'] !== $change || $intent['report'] !== $report || $intent['view'] !== $view || !\in_array($intent['case'], ['*', $case], true)) {
                continue;
            }
            foreach ($selectors[$index] as $key => $value) {
                if (!\array_key_exists($key, $record) || $record[$key] !== $value) {
                    continue 2;
                }
            }
            return $index;
        }
        return null;
    }
}
