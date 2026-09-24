<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter;

use LogicException;

/**
 * The value grammar of every `--format-opt` key, written once.
 *
 * Each reader used to parse its own key, and five readers produced four
 * policies: an unparsable `violations=` meant "no limit", an unparsable
 * `contributors=` meant zero, and `top=2.9` meant 2 in one format and the
 * default in another. Here a key has one grammar, {@see self::problem()} is
 * what the command-line door refuses by before the analysis starts, and the
 * typed readers below are what formatters call on a value that door already
 * accepted — so a reader that finds an unparsable value is a wiring defect,
 * not a fallback to a default.
 *
 * `rank-by` and `top` are read by two formats each; the grammar is per key,
 * so both formats read them identically.
 */
final class FormatOptionValue
{
    private const string COUNT = 'a whole number, 0 or more';
    private const string POSITIVE = 'a whole number, 1 or more';
    private const string LIMIT = 'a whole number, 0 or more, or "all"';
    private const string RANK_BY = 'one of: count, density';
    private const string NAME = 'a non-empty name';

    /** @var array<string, string> key => what a value of it must be */
    private const array GRAMMAR = [
        'contributors' => self::COUNT,
        'limit' => self::LIMIT,
        'project-name' => self::NAME,
        'rank-by' => self::RANK_BY,
        'top' => self::POSITIVE,
        'violations' => self::LIMIT,
    ];

    /**
     * Keys that set one value between them, in the order a refusal names
     * them. `limit` is `violations` reading 0 as "no cap" instead of "none";
     * written together, a reader would have to pick one silently.
     *
     * @var list<list<string>>
     */
    private const array ONE_VALUE = [['violations', 'limit']];

    /** @var list<string> */
    private const array RANKINGS = ['count', 'density'];

    /**
     * What `$raw` would have to be for `$key` to parse, or null when it parses.
     *
     * @throws LogicException for a key no grammar is written for — a formatter
     *                        declared a key without saying what its value is
     */
    public static function problem(string $key, string $raw): ?string
    {
        $grammar = self::GRAMMAR[$key] ?? throw new LogicException(\sprintf(
            'The --format-opt key "%s" is declared by a formatter but has no value grammar in %s.',
            $key,
            self::class,
        ));

        $parses = match ($grammar) {
            self::COUNT => self::wholeNumber($raw) !== null,
            self::POSITIVE => (self::wholeNumber($raw) ?? 0) >= 1,
            self::LIMIT => $raw === 'all' || self::wholeNumber($raw) !== null,
            self::RANK_BY => \in_array($raw, self::RANKINGS, true),
            default => $raw !== '',
        };

        return $parses ? null : $grammar;
    }

    /**
     * Every key that sets the same value as `$key`, `$key` included, in the
     * order a refusal names them; `[$key]` for a key no other key spells.
     *
     * @return list<string>
     */
    public static function spellingsOf(string $key): array
    {
        foreach (self::ONE_VALUE as $spellings) {
            if (\in_array($key, $spellings, true)) {
                return $spellings;
            }
        }

        return [$key];
    }

    /** @return list<string> every key a grammar is written for */
    public static function keys(): array
    {
        return array_keys(self::GRAMMAR);
    }

    public static function count(string $key, string $raw): int
    {
        self::assertAccepted($key, $raw);

        return self::wholeNumber($raw) ?? 0;
    }

    public static function positive(string $key, string $raw): int
    {
        return self::count($key, $raw);
    }

    /** Null for `all`. */
    public static function limit(string $key, string $raw): ?int
    {
        self::assertAccepted($key, $raw);

        return $raw === 'all' ? null : self::wholeNumber($raw);
    }

    /** @return 'count'|'density' */
    public static function rankBy(string $raw): string
    {
        self::assertAccepted('rank-by', $raw);

        return $raw === 'density' ? 'density' : 'count';
    }

    private static function assertAccepted(string $key, string $raw): void
    {
        $problem = self::problem($key, $raw);
        if ($problem !== null) {
            throw new LogicException(\sprintf(
                '--format-opt %s=%s reached a formatter unparsed; the command line must refuse it first (expected %s).',
                $key,
                $raw,
                $problem,
            ));
        }
    }

    private static function wholeNumber(string $raw): ?int
    {
        if (preg_match('/^(0|[1-9][0-9]*)$/', $raw) !== 1) {
            return null;
        }

        $value = filter_var($raw, \FILTER_VALIDATE_INT);

        return $value === false ? null : $value;
    }
}
