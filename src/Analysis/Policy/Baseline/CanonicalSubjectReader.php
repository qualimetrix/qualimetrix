<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use Closure;
use JsonException;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusedPosition;
use stdClass;

/** Reads canonical subject blocks from the owning reader's sequential line source. */
final class CanonicalSubjectReader
{
    private const string INDENT = '  ';
    private const string SUBJECT_INDENT = self::INDENT . self::INDENT;
    private const string ENTRY_INDENT = self::SUBJECT_INDENT . self::INDENT;
    private const string SUBJECT_LINE = '/^' . self::SUBJECT_INDENT . '("(?:[^"\\\\]|\\\\.)*"): \\[$/';
    private const string UNDECODABLE = "\x00undecodable";
    private const int ENTRY_DEPTH_LIMIT = CanonicalEnvelope::DOCUMENT_DEPTH_LIMIT - 3;

    private ?RefusedPosition $duplicate = null;

    /** @param Closure(): ?string $nextLine */
    public function __construct(
        private readonly Closure $nextLine,
        private readonly string $path,
        private readonly ?BaselineEntryParser $entryParser,
    ) {}

    public function duplicate(): ?RefusedPosition
    {
        return $this->duplicate;
    }

    /**
     * A repeated subject key is a refusal rather than something to resolve.
     * `json_decode` keeps the last of two identical object keys, so a
     * streaming reader that kept both would apply ceilings the other path
     * discards.
     *
     * **A comma is a claim about the next line, and it is checked as one.**
     * JSON puts a comma between two members and forbids one before the closing
     * brace, so a block closed with `],` obliges a further subject block and a
     * block closed with `]` obliges the end of `entries`. Reading the two
     * closers as interchangeable would accept documents `json_decode` rejects
     * — a missing comma between blocks, or a trailing one left behind by
     * deleting the last block by hand — and accepting a file as something it
     * is not is the one direction this reader must never take.
     *
     * `entries` is open here, so at least one block must follow; the writer
     * spells an empty entry set `{}` on the field's own line.
     *
     * @return array{list<BaselineEntry>, list<InertBaselineEntry>}|null
     *
     * @phpstan-impure
     */
    public function readSubjects(): ?array
    {
        $entries = [];
        $inert = [];
        $seen = [];

        while (true) {
            $subjectKey = $this->parseSubjectLine($this->readLine());

            if ($subjectKey === null) {
                return null;
            }

            if (isset($seen[$subjectKey])) {
                $this->duplicate ??= RefusedPosition::open(['entries', $subjectKey], $subjectKey);
            }

            $seen[$subjectKey] = true;

            $another = $this->readSubjectEntries($subjectKey, $entries, $inert);

            if ($another === null) {
                return null;
            }

            if (!$another) {
                return $this->readLine() === self::INDENT . '}' ? [$entries, $inert] : null;
            }
        }
    }

    /**
     * Reads one subject's entries and reports what its closing line promised:
     * `true` for `],` — another block follows — and `false` for `]`, the last
     * block. `null` is the refusal, and the caller holds the promise to the
     * line that comes next.
     *
     * @param list<BaselineEntry> $entries
     * @param list<InertBaselineEntry> $inert
     *
     * @param-out list<BaselineEntry> $entries
     * @param-out list<InertBaselineEntry> $inert
     *
     * @phpstan-impure
     */
    private function readSubjectEntries(string $subjectKey, array &$entries, array &$inert): ?bool
    {
        $index = 0;
        do {
            $line = $this->readLine();

            if ($line === null || !str_starts_with($line, self::ENTRY_INDENT)) {
                return null;
            }

            $payload = substr($line, \strlen(self::ENTRY_INDENT));
            $last = !str_ends_with($payload, ',');
            $decoded = $this->decode($last ? $payload : substr($payload, 0, -1), self::ENTRY_DEPTH_LIMIT);

            if ($decoded === self::UNDECODABLE) {
                return null;
            }

            if (!$this->admitEntry($decoded, $subjectKey, $index)) {
                return null;
            }
            $this->collectEntry($decoded, $subjectKey, $entries, $inert);
            ++$index;
        } while (!$last);

        return match ($this->readLine()) {
            self::SUBJECT_INDENT . '],' => true,
            self::SUBJECT_INDENT . ']' => false,
            default => null,
        };
    }

    private function admitEntry(mixed $decoded, string $subjectKey, int $index): bool
    {
        if (!$decoded instanceof stdClass) {
            return true;
        }
        try {
            BaselineFileShape::assertEntryKeys((array) $decoded, $this->path, $subjectKey, $index);
        } catch (ConfigurationRefusal) {
            return false;
        }

        return true;
    }

    /**
     * @param list<BaselineEntry> $entries
     * @param list<InertBaselineEntry> $inert
     *
     * @param-out list<BaselineEntry> $entries
     * @param-out list<InertBaselineEntry> $inert
     */
    private function collectEntry(mixed $decoded, string $subjectKey, array &$entries, array &$inert): void
    {
        if ($this->entryParser === null) {
            return;
        }
        BaselineFileShape::normalizeValues($decoded);
        $entry = $this->entryParser->parse($subjectKey, $decoded);
        if ($entry instanceof InertBaselineEntry) {
            $inert[] = $entry;

            return;
        }
        $entries[] = $entry;
    }

    private function parseSubjectLine(?string $line): ?string
    {
        if ($line === null || preg_match(self::SUBJECT_LINE, $line, $match) !== 1) {
            return null;
        }

        $key = $this->decode($match[1], self::ENTRY_DEPTH_LIMIT);

        return \is_string($key) ? $key : null;
    }

    private function readLine(): ?string
    {
        return ($this->nextLine)();
    }

    /** @param positive-int $depthLimit */
    private function decode(string $json, int $depthLimit): mixed
    {
        try {
            return json_decode($json, false, $depthLimit, \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return self::UNDECODABLE;
        }
    }
}
