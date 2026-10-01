<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedDocument;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedListInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedMapInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;

/** Typed reads of one producer's already judged canonical document paths. */
final readonly class ResolvedRuleOptionValues
{
    /** @param list<string> $prefix */
    public function __construct(private ResolvedDocument $document, private string $producer, private array $prefix = []) {}

    public function atLevel(string $level): self
    {
        return new self($this->document, $this->producer, [...$this->prefix, $level]);
    }

    public function node(string $key): ?ResolvedValueInterface
    {
        return $this->document->get('rules', $this->producer, ...[...$this->prefix, $key]);
    }

    public function boolean(string $key, bool $default): bool
    {
        $node = $this->node($key);
        if ($node === null) {
            return $default;
        }
        $value = $node->plain();
        return \is_bool($value) ? $value : self::wrongType($key);
    }

    public function integer(string $key, int $default): int
    {
        $node = $this->node($key);
        if ($node === null) {
            return $default;
        }
        $value = $node->plain();
        return \is_int($value) ? $value : self::wrongType($key);
    }

    public function number(string $key, int|float $default): int|float
    {
        $node = $this->node($key);
        if ($node === null) {
            return $default;
        }
        $value = $node->plain();
        return \is_int($value) || \is_float($value) ? $value : self::wrongType($key);
    }

    public function text(string $key, string $default): string
    {
        $node = $this->node($key);
        if ($node === null) {
            return $default;
        }
        $value = $node->plain();
        return \is_string($value) ? $value : self::wrongType($key);
    }

    public function list(string $key): ?ResolvedListInterface
    {
        $node = $this->node($key);
        return $node === null || $node instanceof ResolvedListInterface ? $node : self::wrongType($key);
    }

    public function map(string $key): ?ResolvedMapInterface
    {
        $node = $this->node($key);
        return $node === null || $node instanceof ResolvedMapInterface ? $node : self::wrongType($key);
    }

    /**
     * @param list<string> $default
     *
     * @return list<string>
     */
    public function strings(string $key, array $default = []): array
    {
        $list = $this->list($key);
        if ($list === null) {
            return $default;
        }
        $strings = [];
        foreach ($list->items() as $item) {
            $value = $item->plain();
            if (!\is_string($value)) {
                self::wrongType($key);
            }
            $strings[] = $value;
        }
        return $strings;
    }

    private static function wrongType(string $key): never
    {
        throw new LogicException(\sprintf('Resolved rule option "%s" violates its declared value type.', $key));
    }
}
