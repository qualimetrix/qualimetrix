<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Configuration;

use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedListInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedMapInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedOpaqueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusedPosition;

/**
 * One spot of the resolved `architecture` section: the value written there,
 * and a refusal that names the layer which wrote it.
 *
 * The engine resolves the section down to the nodes {@see ArchitectureSection}
 * declares; a criterion list and an allow target it carries unread, because
 * each may be written in two shapes. Below such a node the spot follows the
 * written value, and a refusal names that value's writer at the deeper path
 * the author wrote.
 *
 * `~` is "not written" at every depth, below an unread node too: such a spot
 * reads as absent, while {@see keys()} still lists the key, so a misspelt
 * key written as `~` is not lost to the owner that recognises it.
 */
final readonly class SectionSpot
{
    /**
     * @param list<string> $below authored segments from the anchor down to this spot
     * @param list<string> $path canonical path from the section root, for sentences
     */
    private function __construct(
        private ?ResolvedValueInterface $anchor,
        private array $below,
        private mixed $value,
        public array $path,
    ) {}

    /** The section as resolved; null when no layer wrote it. */
    public static function section(string $key, ?ResolvedValueInterface $node): self
    {
        return self::node([$key], $node);
    }

    /**
     * A node of the section at its canonical path — the whole section, or one
     * value as a single configuration layer wrote it.
     *
     * @param list<string> $path
     */
    public static function node(array $path, ?ResolvedValueInterface $node): self
    {
        return new self($node, [], $node === null ? null : self::unwrap($node), $path);
    }

    public function isWritten(): bool
    {
        return $this->value !== null;
    }

    /** The written value as plain PHP; null when not written. */
    public function value(): mixed
    {
        return $this->value;
    }

    /**
     * The keys written under this spot, `~`-valued ones included.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return \is_array($this->value) ? array_map('strval', array_keys($this->value)) : [];
    }

    public function child(string|int $key): self
    {
        $key = (string) $key;
        $path = [...$this->path, $key];

        if ($this->below === [] && ($this->anchor instanceof ResolvedMapInterface || $this->anchor instanceof ResolvedListInterface)) {
            $node = self::resolvedChild($this->anchor, $key);

            return $node === null
                ? new self($this->anchor, [$key], null, $path)
                : new self($node, [], self::unwrap($node), $path);
        }

        $value = \is_array($this->value) ? $this->value[$key] ?? null : null;

        return new self($this->anchor, [...$this->below, $key], $value, $path);
    }

    private static function resolvedChild(ResolvedMapInterface|ResolvedListInterface $parent, string $key): ?ResolvedValueInterface
    {
        if ($parent instanceof ResolvedMapInterface) {
            return $parent->get($key);
        }

        return ctype_digit($key) ? $parent->items()[(int) $key] ?? null : null;
    }

    /** `architecture.layers[0].name` — the canonical path for a sentence. */
    public function display(): string
    {
        return Provenance::display($this->path);
    }

    /**
     * A refusal of what is written here, naming its writer — every layer that
     * wrote into a merged node, the one that won a leaf or wrote a list.
     *
     * @param ?list<string> $accepted the recognised alternatives, for a refused key or word
     * @param ?string $written what is refused, when it is not this spot's last segment
     */
    public function refusal(string $summary, ?array $accepted = null, ?string $written = null): ConfigurationRefusal
    {
        $anchor = $this->anchor
            ?? throw new LogicException(\sprintf('Nothing is written at "%s" to refuse.', $this->display()));

        if ($this->below === [] && $accepted === null && $written === null) {
            return Provenance::refusalOf($anchor->contributors(), $summary);
        }

        $writers = $anchor->contributors();
        $last = $writers[\count($writers) - 1];
        if ($last->path === null) {
            return Provenance::refusalOf($writers, $summary);
        }

        $segments = [...$last->path, ...$this->below];
        $written ??= $segments[\count($segments) - 1];

        return Provenance::refusalOf(
            $writers,
            $summary,
            $accepted === null ? RefusedPosition::open($segments, $written) : RefusedPosition::closed($segments, $written, $accepted),
        );
    }

    /**
     * A refusal of a relation between several spots, naming every layer that
     * wrote any of them.
     *
     * @param list<self> $spots
     */
    public static function refusalAcross(array $spots, string $summary): ConfigurationRefusal
    {
        $writers = [];
        foreach ($spots as $spot) {
            if ($spot->anchor !== null) {
                $writers = [...$writers, ...$spot->anchor->contributors()];
            }
        }

        if ($writers === []) {
            throw new LogicException(\sprintf('Nothing is written to refuse: %s.', $summary));
        }

        return Provenance::refusalOf($writers, $summary);
    }

    /**
     * An unread node is kept per layer; under a replaced list, where this
     * section declares every one, only one layer can have written it.
     */
    private static function unwrap(ResolvedValueInterface $node): mixed
    {
        return match (true) {
            $node instanceof ResolvedMapInterface => array_map(self::unwrap(...), $node->entries()),
            $node instanceof ResolvedListInterface => array_map(self::unwrap(...), $node->items()),
            $node instanceof ResolvedOpaqueInterface => $node->contributions()[\count($node->contributions()) - 1]['value'],
            default => $node->plain(),
        };
    }
}
