<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusedPosition;
use Qualimetrix\Analysis\Policy\Baseline\Contract\BaselineDocument;

/**
 * Recognises canonical baseline layout from bytes held by preflight without
 * holding the decoded document.
 *
 * **This is a recogniser, not a second parser.** Every layout it does not
 * recognise it declines, answering `null` so {@see BaselineLoader} can decode
 * the file whole instead. It never interprets, never repairs and never throws
 * on shape: a file a human reformatted is simply not this layout. Strictness
 * is what makes that safe to rely on — a false negative costs one full decode,
 * while a false positive would mean reading a file as something it is not.
 *
 * Entries go to the same {@see BaselineEntryParser} the whole-document path
 * uses only on the configured semantic pass. The earlier grammar pass never
 * interprets a channel or level.
 *
 * The envelope comes back as read, unvalidated. Which fields a baseline must
 * have and what they may say is {@see BaselineLoader}'s to decide, and both
 * paths have to answer that identically or they are two formats.
 *
 * Each scan has a fresh cursor over one held document.
 */
final class CanonicalBaselineReader
{
    /** Indentation of the canonical layout, as {@see BaselineWriter} emits it. */
    private const string INDENT = '  ';

    /** `  "entries": {` — the line after which subject blocks begin. */
    private const string ENTRIES_OPEN = self::INDENT . '"entries": {';

    /** `  "entries": {}` — the same field with nothing under it. */
    private const string ENTRIES_EMPTY = self::ENTRIES_OPEN . '}';

    private string $bytes;

    private int $offset;

    private string $path;

    private ?RefusedPosition $duplicate = null;

    public function __construct(
        private readonly ?BaselineEntryParser $entryParser,
    ) {}

    /** @return array<string, mixed>|null Canonical envelope, or null for full-document fallback. */
    public static function grammarEnvelope(string $bytes, string $path): ?array
    {
        return (new self(null))->scanBytes($bytes, $path, contentHash: '')['envelope'] ?? null;
    }

    /**
     * @return array{
     *     envelope: array<string, mixed>,
     *     entries: list<BaselineEntry>,
     *     inert: list<InertBaselineEntry>,
     *     contentHash: string
     * }|null
     */
    public function read(BaselineDocument $document): ?array
    {
        return $this->scanBytes($document->bytes(), $document->path, contentHash: $document->contentHash);
    }

    /**
     * @return array{envelope: array<string, mixed>, entries: list<BaselineEntry>, inert: list<InertBaselineEntry>, contentHash: string}|null
     */
    private function scanBytes(string $bytes, string $path, string $contentHash): ?array
    {
        $this->bytes = $bytes;
        $this->offset = 0;
        $this->path = $path;
        $this->duplicate = null;

        if ($this->readLine() !== '{') {
            return null;
        }

        $envelope = $this->readEnvelope();

        if ($envelope === null) {
            return null;
        }

        [$fields, $hasSubjects] = $envelope;

        $collected = $this->readCanonicalSubjects($hasSubjects);

        if ($collected === null) {
            return null;
        }

        return $this->completeCanonical($fields, $collected, $contentHash);
    }

    /** @return array{list<BaselineEntry>, list<InertBaselineEntry>}|null */
    private function readCanonicalSubjects(bool $hasSubjects): ?array
    {
        if (!$hasSubjects) {
            return [[], []];
        }

        $subjects = new CanonicalSubjectReader(fn(): ?string => $this->readLine(), $this->path, $this->entryParser);
        $collected = $subjects->readSubjects();
        if ($collected !== null) {
            $this->duplicate ??= $subjects->duplicate();
        }

        return $collected;
    }

    /**
     * @param array<string, mixed> $fields
     * @param array{list<BaselineEntry>, list<InertBaselineEntry>} $collected
     *
     * @return array{envelope: array<string, mixed>, entries: list<BaselineEntry>, inert: list<InertBaselineEntry>, contentHash: string}|null
     */
    private function completeCanonical(array $fields, array $collected, string $contentHash): ?array
    {
        // Bytes past the closing brace would belong to a different document
        // than the one just scanned.
        if ($this->readLine() !== '}' || $this->offset !== \strlen($this->bytes)) {
            return null;
        }

        try {
            BaselineFileShape::envelope([...$fields, 'entries' => []], $this->path);
        } catch (ConfigurationRefusal) {
            return null;
        }

        if ($this->duplicate !== null) {
            throw ConfigurationRefusal::atBaselineFileKey(
                $this->path,
                $this->duplicate,
                'Duplicate baseline JSON member at ' . implode(' › ', $this->duplicate->segments),
            );
        }

        return [
            'envelope' => $fields,
            'entries' => $collected[0],
            'inert' => $collected[1],
            'contentHash' => $contentHash,
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

            $field = CanonicalEnvelope::parseLine($line);

            if ($field === null) {
                return null;
            }

            if (\array_key_exists($field[0], $fields)) {
                $this->duplicate ??= RefusedPosition::closed([$field[0]], $field[0], BaselineFileShape::ENVELOPE);
            }

            $fields[$field[0]] = $field[1];
        }
    }

    /**
     * Reads one line from the held bytes without copying all lines at once.
     *
     * A line with no trailing newline is the last in the file, and this layout
     * never ends a line that way, so it is reported as absent rather than as
     * content.
     *
     * @phpstan-impure
     */
    private function readLine(): ?string
    {
        $end = strpos($this->bytes, "\n", $this->offset);
        if ($end === false) {
            return null;
        }
        $line = substr($this->bytes, $this->offset, $end - $this->offset);
        $this->offset = $end + 1;

        return $line;
    }

}
