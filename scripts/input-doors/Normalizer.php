<?php

declare(strict_types=1);

/**
 * One normalization list, not two.
 *
 * `finding-gate/normalization.tsv` is the authority over the surfaces the gate
 * produces; `input-doors/normalization-supplement.tsv` adds the surfaces it
 * does not own and, with `gate_owned=yes` plus a reason, the one override the
 * measurement forced. A second, private list would diverge from the first in
 * silence, which is the failure this class exists to avoid.
 */

namespace Qualimetrix\InputDoors;

final class Normalizer
{
    /** @param list<array{string, string, string, string, string}> $rows */
    public function __construct(private readonly array $rows, private readonly bool $enabled = true) {}

    public function withoutSupplementRow(string $surface, string $locator): self
    {
        $kept = [];

        foreach ($this->rows as $row) {
            if ($row[0] === $surface && $row[1] === $locator && $row[3] !== 'gate') {
                continue;
            }

            $kept[] = $row;
        }

        return new self($kept, $this->enabled);
    }

    public function disabled(): self
    {
        return new self($this->rows, false);
    }

    /** @return list<string> surfaces this list can normalize */
    public function surfaces(): array
    {
        $surfaces = [];

        foreach ($this->rows as $row) {
            $surfaces[$row[0]] = true;
        }

        $names = array_keys($surfaces);
        sort($names, \SORT_STRING);

        return $names;
    }

    public function normalize(string $surface, string $text): string
    {
        if (!$this->enabled) {
            return $text;
        }

        foreach ($this->rows as [$rowSurface, $locator, $kind]) {
            if ($rowSurface !== $surface) {
                continue;
            }

            $text = match ($kind) {
                'json-path' => $this->blankJsonPath($text, $locator),
                'line-regex' => $this->blankLineRegex($text, $locator),
                default => $text,
            };
        }

        return $text;
    }

    private function blankLineRegex(string $text, string $pattern): string
    {
        $replaced = preg_replace($pattern, '<normalized>', $text);

        return $replaced ?? $text;
    }

    /**
     * A locator that does not resolve is not an error: the same surface is
     * observed on commands whose output legitimately lacks the field. A
     * locator that resolves is blanked, never deleted, so the shape of the
     * document stays comparable.
     */
    private function blankJsonPath(string $text, string $locator): string
    {
        /** @var mixed $decoded */
        $decoded = json_decode($text, true);

        if (!\is_array($decoded)) {
            return $text;
        }

        $segments = explode('.', $locator);
        $blanked = $this->blankSegment($decoded, $segments);

        if ($blanked === null) {
            return $text;
        }

        $encoded = json_encode($blanked, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        return $encoded === false ? $text : $encoded;
    }

    /**
     * @param array<array-key, mixed> $document
     * @param list<string> $segments
     *
     * @return array<array-key, mixed>|null null when the path did not resolve
     */
    private function blankSegment(array $document, array $segments): ?array
    {
        $head = array_shift($segments);

        if ($head === null || !\array_key_exists($head, $document)) {
            return null;
        }

        if ($segments === []) {
            $document[$head] = '<normalized>';

            return $document;
        }

        /** @var mixed $child */
        $child = $document[$head];

        if (!\is_array($child)) {
            return null;
        }

        $blanked = $this->blankSegment($child, $segments);

        if ($blanked === null) {
            return null;
        }

        $document[$head] = $blanked;

        return $document;
    }
}
