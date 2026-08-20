<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use HashContext;
use JsonException;
use RuntimeException;
use SplFileObject;

/**
 * Reads a baseline file written in the canonical layout — one entry per line
 * inside a single valid JSON document — without ever holding the decoded
 * document.
 *
 * **This is a recogniser, not a second parser.** Every layout it does not
 * recognise it declines, answering `null` so {@see BaselineLoader} can decode
 * the file whole instead. It never interprets, never repairs and never throws
 * on shape: a file a human reformatted is simply not this layout. Strictness
 * is what makes that safe to rely on — a false negative costs one full decode,
 * while a false positive would mean reading a file as something it is not.
 *
 * Entries go straight to the same {@see BaselineEntryParser} the whole-document
 * path uses, line by line. That is the point: what the file costs to read stops
 * growing with how many entries stand between its first line and its last.
 *
 * The envelope comes back as read, unvalidated. Which fields a baseline must
 * have and what they may say is {@see BaselineLoader}'s to decide, and both
 * paths have to answer that identically or they are two formats.
 *
 * **An instance reads one file.** The cursor and the running hash are the
 * object, which is why the loader builds one per read rather than holding a
 * shared reader.
 */
final class CanonicalBaselineReader
{
    /** Indentation of the canonical layout, as {@see BaselineWriter} emits it. */
    private const string INDENT = '  ';

    private const string SUBJECT_INDENT = self::INDENT . self::INDENT;

    private const string ENTRY_INDENT = self::INDENT . self::INDENT . self::INDENT;

    /** `  "entries": {` — the line after which subject blocks begin. */
    private const string ENTRIES_OPEN = self::INDENT . '"entries": {';

    /** `  "entries": {}` — the same field with nothing under it. */
    private const string ENTRIES_EMPTY = self::ENTRIES_OPEN . '}';

    /** `  "<json string>": <json value>,` */
    private const string ENVELOPE_LINE = '/^' . self::INDENT . '("(?:[^"\\\\]|\\\\.)*"): (.+),$/';

    /** `    "<json string>": [` */
    private const string SUBJECT_LINE = '/^' . self::SUBJECT_INDENT . '("(?:[^"\\\\]|\\\\.)*"): \\[$/';

    /** Tells "this is not JSON" apart from a decoded `null`. */
    private const string UNDECODABLE = "\x00undecodable";

    /**
     * The nesting budget `json_decode()` is given for the whole document on
     * the fallback path, and therefore the budget this reader has to spend
     * between them to mean the same thing.
     */
    private const int DOCUMENT_DEPTH_LIMIT = 512;

    /**
     * Containers standing between the document and one entry: the document
     * object, the `entries` object, and the subject's array.
     *
     * A value decoded on its own starts counting from nothing, so its budget
     * has to be reduced by what its position in the document already spends —
     * otherwise a deeply nested entry is read here and refused there, and
     * which path ran becomes visible in the answer.
     */
    private const int ENTRY_ENCLOSING_CONTAINERS = 3;

    /** Containers between the document and an envelope value: the document object. */
    private const int ENVELOPE_ENCLOSING_CONTAINERS = 1;

    private const int ENTRY_DEPTH_LIMIT = self::DOCUMENT_DEPTH_LIMIT - self::ENTRY_ENCLOSING_CONTAINERS;

    private const int ENVELOPE_DEPTH_LIMIT = self::DOCUMENT_DEPTH_LIMIT - self::ENVELOPE_ENCLOSING_CONTAINERS;

    private SplFileObject $file;

    private HashContext $hash;

    public function __construct(
        private readonly BaselineEntryParser $entryParser,
    ) {}

    /**
     * @return array{
     *     envelope: array<string, mixed>,
     *     entries: list<BaselineEntry>,
     *     inert: list<InertBaselineEntry>,
     *     contentHash: string
     * }|null
     */
    public function read(string $path): ?array
    {
        try {
            $this->file = new SplFileObject($path, 'rb');
        } catch (RuntimeException) {
            return null;
        }

        $this->hash = hash_init('sha256');

        return $this->scan();
    }

    /**
     * @return array{
     *     envelope: array<string, mixed>,
     *     entries: list<BaselineEntry>,
     *     inert: list<InertBaselineEntry>,
     *     contentHash: string
     * }|null
     */
    private function scan(): ?array
    {
        if ($this->readLine() !== '{') {
            return null;
        }

        $envelope = $this->readEnvelope();

        if ($envelope === null) {
            return null;
        }

        [$fields, $hasSubjects] = $envelope;

        $collected = $hasSubjects ? $this->readSubjects() : [[], []];

        if ($collected === null) {
            return null;
        }

        // Bytes past the closing brace would belong to a different document
        // than the one just scanned.
        if ($this->readLine() !== '}' || !$this->atEndOfFile()) {
            return null;
        }

        return [
            'envelope' => $fields,
            'entries' => $collected[0],
            'inert' => $collected[1],
            'contentHash' => hash_final($this->hash),
        ];
    }

    /**
     * Reads envelope fields in whatever order the file spells them, up to the
     * `entries` field, and reports whether subject blocks follow it.
     *
     * @return array{array<string, mixed>, bool}|null
     *
     * @phpstan-impure
     */
    private function readEnvelope(): ?array
    {
        $fields = [];

        while (true) {
            $line = $this->readLine();

            if ($line === self::ENTRIES_EMPTY) {
                return [$fields, false];
            }

            if ($line === self::ENTRIES_OPEN) {
                return [$fields, true];
            }

            $field = $this->parseEnvelopeLine($line);

            if ($field === null || \array_key_exists($field[0], $fields)) {
                return null;
            }

            $fields[$field[0]] = $field[1];
        }
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
    private function readSubjects(): ?array
    {
        $entries = [];
        $inert = [];
        $seen = [];

        while (true) {
            $subjectKey = $this->parseSubjectLine($this->readLine());

            if ($subjectKey === null || isset($seen[$subjectKey])) {
                return null;
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

            $entry = $this->entryParser->parse($subjectKey, $decoded);

            if ($entry instanceof InertBaselineEntry) {
                $inert[] = $entry;
            } else {
                $entries[] = $entry;
            }
        } while (!$last);

        return match ($this->readLine()) {
            self::SUBJECT_INDENT . '],' => true,
            self::SUBJECT_INDENT . ']' => false,
            default => null,
        };
    }

    /**
     * @return array{string, mixed}|null
     */
    private function parseEnvelopeLine(?string $line): ?array
    {
        if ($line === null || preg_match(self::ENVELOPE_LINE, $line, $match) !== 1) {
            return null;
        }

        $key = $this->decode($match[1], self::ENVELOPE_DEPTH_LIMIT);
        $value = $this->decode($match[2], self::ENVELOPE_DEPTH_LIMIT);

        if (!\is_string($key) || $value === self::UNDECODABLE) {
            return null;
        }

        return [$key, $value];
    }

    private function parseSubjectLine(?string $line): ?string
    {
        if ($line === null || preg_match(self::SUBJECT_LINE, $line, $match) !== 1) {
            return null;
        }

        $key = $this->decode($match[1], self::ENTRY_DEPTH_LIMIT);

        return \is_string($key) ? $key : null;
    }

    /**
     * Reads one line, feeds its raw bytes to the running hash, and answers
     * with the line's content.
     *
     * The hash is built here rather than from the finished string, because
     * holding the finished string is the one thing this reader exists to avoid.
     *
     * A line with no trailing newline is the last in the file, and this layout
     * never ends a line that way, so it is reported as absent rather than as
     * content.
     *
     * @phpstan-impure
     */
    private function readLine(): ?string
    {
        if ($this->file->eof()) {
            return null;
        }

        $line = $this->file->fgets();

        hash_update($this->hash, $line);

        return str_ends_with($line, "\n") ? substr($line, 0, -1) : null;
    }

    /**
     * `eof()` is only true once a read has come up empty, so asking it before
     * the read would call every complete file truncated.
     *
     * @phpstan-impure
     */
    private function atEndOfFile(): bool
    {
        return $this->file->eof() || $this->file->fgets() === '';
    }

    /**
     * {@see UNDECODABLE} rather than `null`, because `null` is a value a
     * baseline field can legitimately hold.
     *
     * @param positive-int $depthLimit
     */
    private function decode(string $json, int $depthLimit): mixed
    {
        try {
            return json_decode($json, true, $depthLimit, \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return self::UNDECODABLE;
        }
    }
}
