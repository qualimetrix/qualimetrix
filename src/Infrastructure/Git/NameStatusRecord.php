<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Git;

use RuntimeException;

/**
 * One row of `git diff --name-status -z`, holding the bytes git wrote.
 *
 * The NUL-separated form is asked for because the default one is not a format.
 * With `core.quotePath` on — its default — git wraps any path holding a byte
 * above 0x7F, a quote, a backslash or a control character in C quotes and
 * escapes the bytes octally, so a reader taking the row literally receives a
 * name no file has, and the octal escapes can even read as directory
 * separators. `-z` removes that transformation rather than inverting it: every
 * field is the name's own bytes, whatever `core.quotePath` says. Inverting it
 * instead would mean carrying a second implementation of git's quoting rules
 * and keeping it in step with git's.
 *
 * The price is that field boundaries no longer arrive one per line. The stream
 * is flat, and how many path fields a record owns is decided by its status
 * letter: `R` and `C` own two, every other status owns one. Miscount once and
 * every later record reads a status where a path is, so an unreadable field
 * stops the walk loudly instead of resynchronising on the next thing that
 * looks like a status.
 */
final readonly class NameStatusRecord
{
    /**
     * Both names are raw bytes from git, deliberately not path value objects:
     * this record exists precisely to hold what git said before any path model
     * touches it.
     *
     * @param string $status one status letter, without the similarity index
     * @param string $rawPath the new name — the only name for a non-rename
     * @param string|null $rawOldPath the source name of a rename or a copy
     */
    public function __construct(
        public string $status,
        public string $rawPath,
        public ?string $rawOldPath,
    ) {}

    /**
     * Walks the whole `git diff --name-status -z` stream.
     *
     *
     * @throws RuntimeException when a field is not where the format puts it;
     *                          git's machine format is a contract with the
     *                          tool, so a stream this cannot walk is a broken
     *                          environment rather than bad user input
     *
     * @return list<self>
     */
    public static function parseStream(string $output): array
    {
        $fields = explode("\0", $output);
        $count = \count($fields);
        $records = [];
        $index = 0;

        while ($index < $count) {
            $status = $fields[$index];

            // Every record is NUL-terminated, so explode() always leaves a
            // final empty field; an empty stream is that field alone.
            if ($status === '' && $index === $count - 1) {
                break;
            }

            $records[] = self::readAt($fields, $index);
            $index += 1 + self::pathCount($status);
        }

        return $records;
    }

    /**
     * Reads the one record starting at `$index`, status field first.
     *
     * @param list<string> $fields
     *
     * @throws RuntimeException when the field there is not a status, or the
     *                          paths the status owns are not all present
     */
    private static function readAt(array $fields, int $index): self
    {
        $status = $fields[$index];

        if ($status === '') {
            throw new RuntimeException(
                'Unreadable `git diff --name-status -z` output: an empty status field before the end of the stream.',
            );
        }

        if (preg_match('/^[A-Z]\d*$/', $status) !== 1) {
            throw new RuntimeException(\sprintf(
                'Unreadable `git diff --name-status -z` output: expected a status field, got "%s".',
                $status,
            ));
        }

        $pathCount = self::pathCount($status);
        $paths = \array_slice($fields, $index + 1, $pathCount);

        if (\count($paths) !== $pathCount || \in_array('', $paths, true)) {
            throw new RuntimeException(\sprintf(
                'Truncated `git diff --name-status -z` output: status "%s" is missing a path field.',
                $status,
            ));
        }

        return $pathCount === 2
            ? new self($status[0], $paths[1], $paths[0])
            : new self($status[0], $paths[0], null);
    }

    /**
     * How many path fields the record with this status owns.
     *
     * The one rule the walk's arithmetic rests on, so it is named rather than
     * spelled out at each of the two places that need it: reading a record and
     * stepping past it.
     */
    private static function pathCount(string $status): int
    {
        return $status[0] === 'R' || $status[0] === 'C' ? 2 : 1;
    }
}
