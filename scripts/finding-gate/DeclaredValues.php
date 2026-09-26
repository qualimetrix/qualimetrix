<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * Values a step moved on purpose: an intent a person writes, and the exact
 * table a derive run measures under it.
 *
 * The intent (`declared-values.tsv`) names what may move — a published finding
 * field or a metric key, at one subject level or at every level (`*`) — and
 * why. The derived table (`declared-values.derived.tsv`) names every value that
 * did move under an intent: the record or symbol, the key, and the values
 * before and after as JSON spellings, so an empty string is `""` and never an
 * empty column. A run holds the measurement to the table byte for byte, so
 * the table licenses those values and no others: a shift the table does not
 * name — the neighbouring symbol's value, one more record — is not declared by
 * the intent it happens to share a key with. A derived row whose key no intent
 * declares is refused at load, which is what keeps a derive run from writing a
 * licence nobody asked for. An intent under which nothing moved is stale.
 */
final class DeclaredValues
{
    public const array COLUMNS = ['kind', 'key', 'level', 'reason'];

    public const array DERIVED_COLUMNS = ['kind', 'key', 'subject', 'from', 'to'];

    public const string INDEX = 'declared-values.tsv';

    public const string DERIVED = 'declared-values.derived.tsv';

    public const string FIELD = 'field';

    public const string METRIC = 'metric';

    public const string EXIT = 'exit';

    public const array COMMANDS = ['check', 'directives', 'graph:export', 'rules', 'baseline:generate', 'baseline:explain', 'baseline:update', 'baseline:cleanup', 'baseline:rename-channels', 'debug:layer-assignment'];

    public const string EVERY_LEVEL = '*';

    /** @var array<string, true> intent key => credited */
    private array $credited = [];

    /**
     * @param array<string, array{kind: string, key: string, level: string, reason: string}> $intents
     * @param list<array{kind: string, key: string, subject: string, from: string, to: string}> $derived
     */
    private function __construct(
        private readonly array $intents,
        private readonly array $derived,
        private readonly string $derivedText,
    ) {}

    public static function load(string $root): self
    {
        $intents = [];

        foreach (DeclarationTable::rows($root, self::INDEX, self::COLUMNS) as $index => $row) {
            DeclarationTable::oneOf(self::INDEX, $index + 1, 'kind', $row['kind'], [self::FIELD, self::METRIC, self::EXIT]);
            DeclarationTable::oneOf(self::INDEX, $index + 1, 'level', $row['level'], [self::EVERY_LEVEL, ...SubjectLevel::levels()]);
            if ($row['kind'] === self::EXIT && ($row['level'] !== '*' || !\in_array($row['key'], self::COMMANDS, true))) {
                throw new GateError('An exit intention requires an exact command class and level *.');
            }
            $key = self::intentKey($row['kind'], $row['key']);

            if (isset($intents[$key])) {
                throw new GateError(\sprintf('%s row %d declares the %s "%s" a second time.', self::INDEX, $index + 1, $row['kind'], $row['key']));
            }

            $intents[$key] = ['kind' => $row['kind'], 'key' => $row['key'], 'level' => $row['level'], 'reason' => $row['reason']];
        }

        $derived = [];
        $seen = [];

        foreach (DeclarationTable::rows($root, self::DERIVED, self::DERIVED_COLUMNS) as $index => $row) {
            if (!isset($intents[self::intentKey($row['kind'], $row['key'])])) {
                throw new GateError(\sprintf(
                    '%s row %d moves the %s "%s", which no intent in %s declares. A derived table may only measure'
                    . ' what an intent names.',
                    self::DERIVED,
                    $index + 1,
                    $row['kind'],
                    $row['key'],
                    self::INDEX,
                ));
            }

            DeclarationTable::once(
                self::DERIVED,
                $index + 1,
                self::intentKey($row['kind'], $row['key']) . "\0" . $row['subject'],
                \sprintf('the %s "%s" of %s', $row['kind'], $row['key'], $row['subject']),
                $seen,
            );

            $derived[] = ['kind' => $row['kind'], 'key' => $row['key'], 'subject' => $row['subject'], 'from' => $row['from'], 'to' => $row['to']];
        }

        $path = $root . '/' . self::DERIVED;

        return new self($intents, $derived, is_file($path) ? Fs::read($path) : '');
    }

    public function count(): int
    {
        return \count($this->intents);
    }

    /** @return list<array{kind: string, key: string, level: string, reason: string}> */
    public function intents(): array
    {
        return array_values($this->intents);
    }

    /** @return list<array{kind: string, key: string, subject: string, from: string, to: string}> */
    public function derived(): array
    {
        return $this->derived;
    }

    /** The derived table as tracked, for the byte comparison against a fresh measurement. */
    public function derivedText(): string
    {
        return $this->derivedText;
    }

    /** Records that a value moved under this intent. */
    public function credit(string $kind, string $key): void
    {
        $this->credited[self::intentKey($kind, $key)] = true;
    }

    /** @return list<array{scope: string, detail: string}> */
    public function stale(): array
    {
        $stale = [];

        foreach ($this->intents as $key => $intent) {
            if (!isset($this->credited[$key])) {
                $stale[] = [
                    'scope' => self::INDEX,
                    'detail' => \sprintf('The %s "%s" at level %s', $intent['kind'], $intent['key'], $intent['level']),
                ];
            }
        }

        return $stale;
    }

    private static function intentKey(string $kind, string $key): string
    {
        return $kind . "\0" . $key;
    }
}
