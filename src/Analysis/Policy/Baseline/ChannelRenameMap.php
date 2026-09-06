<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use InvalidArgumentException;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;

/**
 * The declared `old channel name -> new channel name` map a carry is
 * performed by, read from a tab-separated file.
 *
 * **The format is the finding gate's `maps/channels.tsv`**: a header line
 * `old`, `new`, `reason`, then one row per rename, blank lines and `#`
 * comments ignored, a trailing `\r` tolerated so a CRLF checkout is not a
 * refusal, and no whitespace permitted around a field — a value with an
 * invisible edge matches nothing and would be reported as a rename that
 * translated nothing, which is the wrong defect to name.
 *
 * The gate's own reader lives in `scripts/` and is not on the production
 * autoloader, so this is a second implementation of one format rather than a
 * shared one. What keeps the two honest is one corpus of lines run against
 * both,
 * {@see \Qualimetrix\Tests\Analysis\Policy\Baseline\Fixtures\ChannelRenameTsvCorpus},
 * which states each reader's verdict per line — including the five the two
 * deliberately answer differently, each with its reason beside it.
 *
 * **A new name is not checked against the channel registry.** The carry ships
 * a release before the renames it exists to perform, so the names it writes
 * are, by design, names this build has never heard of. Only the *form* of a
 * name is validated — what {@see FindingChannel} accepts, plus the separator
 * {@see BaselineIdentity} reserves. A carry onto an undeclared name therefore
 * produces a file today's `check` reports as inert, and that is the intended
 * intermediate state, not a defect.
 */
final readonly class ChannelRenameMap
{
    /** The header every map file must start with. */
    public const array COLUMNS = ['old', 'new', 'reason'];

    /**
     * @param array<string, string> $renames old channel name => new channel name
     */
    private function __construct(
        public array $renames,
    ) {}

    /**
     * @throws ChannelRenameRefusal when the file is not a well-formed map, or declares a
     *                              set of renames that has no single unambiguous result
     */
    public static function fromString(string $contents, string $origin): self
    {
        $lines = explode("\n", $contents);
        $header = rtrim((string) array_shift($lines), "\r");

        if (trim($header) !== implode("\t", self::COLUMNS)) {
            throw new ChannelRenameRefusal(\sprintf(
                '%s must start with the header "%s".',
                $origin,
                implode(' | ', self::COLUMNS),
            ));
        }

        /** @var array<string, string> $renames */
        $renames = [];
        /** @var array<string, int> $declaredAt old name => the line that declared it */
        $declaredAt = [];
        /** @var array<string, int> $targets new name => the line that produced it */
        $targets = [];

        foreach ($lines as $index => $rawLine) {
            $line = rtrim($rawLine, "\r");
            $number = $index + 2;

            if (trim($line) === '' || str_starts_with($line, '#')) {
                continue;
            }

            [$old, $new] = self::readRow($line, $number, $origin);

            if (isset($declaredAt[$old])) {
                throw new ChannelRenameRefusal(\sprintf(
                    '%s lines %d and %d both rename "%s", so there is no single name to carry it to.',
                    $origin,
                    $declaredAt[$old],
                    $number,
                    $old,
                ));
            }

            if (isset($targets[$new])) {
                throw new ChannelRenameRefusal(\sprintf(
                    '%s lines %d and %d both produce "%s". Two accepted channels collapsing into one is a '
                    . 'merge of accepted debt, not a rename; perform it deliberately rather than as a carry.',
                    $origin,
                    $targets[$new],
                    $number,
                    $new,
                ));
            }

            $renames[$old] = $new;
            $declaredAt[$old] = $number;
            $targets[$new] = $number;
        }

        self::assertNoChain($renames, $declaredAt, $targets, $origin);

        return new self($renames);
    }

    /**
     * @throws ChannelRenameRefusal
     */
    public static function fromFile(string $path): self
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new ChannelRenameRefusal(\sprintf('Cannot read the channel rename map %s.', $path));
        }

        return self::fromString($contents, $path);
    }

    /**
     * The new name for a channel, or `null` when the map says nothing about
     * it.
     */
    public function translate(string $channel): ?string
    {
        return $this->renames[$channel] ?? null;
    }

    /**
     * @return list<string>
     */
    public function oldNames(): array
    {
        return array_keys($this->renames);
    }

    /**
     * A row's `old` and `new`, with the field rules the format states.
     *
     * `reason` is read for its presence only: it is the row's justification
     * for a human reviewer, and the carry has no use for its contents.
     *
     * @throws ChannelRenameRefusal
     *
     * @return array{string, string}
     */
    private static function readRow(string $line, int $number, string $origin): array
    {
        $fields = explode("\t", $line);

        if (\count($fields) !== \count(self::COLUMNS)) {
            throw new ChannelRenameRefusal(\sprintf(
                '%s line %d has %d field(s), expected %d.',
                $origin,
                $number,
                \count($fields),
                \count(self::COLUMNS),
            ));
        }

        foreach ($fields as $position => $field) {
            if ($field !== trim($field)) {
                throw new ChannelRenameRefusal(\sprintf(
                    '%s line %d has whitespace around the "%s" field. A channel is matched by exact equality, '
                    . 'so a value with an invisible edge would carry nothing and be reported as an idle row.',
                    $origin,
                    $number,
                    self::COLUMNS[$position],
                ));
            }
        }

        [$old, $new] = $fields;

        self::assertChannelForm($old, self::COLUMNS[0], $number, $origin);
        self::assertChannelForm($new, self::COLUMNS[1], $number, $origin);

        if ($old === $new) {
            throw new ChannelRenameRefusal(\sprintf(
                '%s line %d renames nothing: the two sides are the same name.',
                $origin,
                $number,
            ));
        }

        return [$old, $new];
    }

    /**
     * @throws ChannelRenameRefusal
     */
    private static function assertChannelForm(string $name, string $column, int $number, string $origin): void
    {
        try {
            new FindingChannel($name);
        } catch (InvalidArgumentException $e) {
            throw new ChannelRenameRefusal(\sprintf(
                '%s line %d has a "%s" field that is not a channel name: %s',
                $origin,
                $number,
                $column,
                $e->getMessage(),
            ));
        }

        // The identity key's separator. A name carrying it loads as an inert
        // entry rather than as the channel it spells, so carrying onto one
        // would be a write nothing could ever address.
        if (str_contains($name, "\x1F")) {
            throw new ChannelRenameRefusal(\sprintf(
                '%s line %d has a "%s" field carrying the ASCII Unit Separator, which a baseline identity '
                . 'reserves and a channel name may never contain.',
                $origin,
                $number,
                $column,
            ));
        }
    }

    /**
     * A row whose target another row renames again declares an identity no
     * row states: applied in one order the carry lands on the middle name,
     * in the other on the last, and the file it produces depends on which.
     *
     * @param array<string, string> $renames
     * @param array<string, int> $declaredAt
     * @param array<string, int> $targets
     *
     * @throws ChannelRenameRefusal
     */
    private static function assertNoChain(array $renames, array $declaredAt, array $targets, string $origin): void
    {
        foreach ($renames as $new) {
            if (!isset($declaredAt[$new])) {
                continue;
            }

            throw new ChannelRenameRefusal(\sprintf(
                '%s line %d produces "%s", which line %d renames again: a chain declares a result no row states.',
                $origin,
                $targets[$new],
                $new,
                $declaredAt[$new],
            ));
        }
    }
}
