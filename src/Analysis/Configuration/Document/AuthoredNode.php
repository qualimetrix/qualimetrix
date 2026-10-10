<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Document;

use LogicException;

/**
 * One node of a layer exactly as its author wrote it: keys in their own
 * spelling, `~` still present, and — when the format reports it — the line.
 *
 * This is what a format loader hands the engine, so the engine never learns
 * the format. A command-line layer gives each option's node a locator (the
 * option name), which then names the source of everything below it.
 */
final readonly class AuthoredNode
{
    /** @param array<string|int, self> $children */
    private function __construct(
        public AuthoredShape $shape,
        public int|float|string|bool|null $scalar,
        public array $children,
        public ?int $line,
        public ?string $locator,
        public ?string $authoredExpression,
    ) {}

    /**
     * @qmx-ignore code-smell.boolean-argument -- `$value` is the scalar as written, and a
     * written `true` is a value like any other: nothing here branches on it.
     */
    public static function scalar(int|float|string|bool|null $value, ?int $line = null, ?string $locator = null, ?string $authoredExpression = null): self
    {
        return new self(AuthoredShape::Scalar, $value, [], $line, $locator, $authoredExpression);
    }

    /** @param array<string, self> $children authored key => node */
    public static function mapping(array $children, ?int $line = null, ?string $locator = null, ?string $authoredExpression = null): self
    {
        return new self(AuthoredShape::Mapping, null, $children, $line, $locator, $authoredExpression);
    }

    /** @param list<self> $items */
    public static function sequence(array $items, ?int $line = null, ?string $locator = null, ?string $authoredExpression = null): self
    {
        return new self(AuthoredShape::Sequence, null, $items, $line, $locator, $authoredExpression);
    }

    /**
     * A parsed tree of PHP values, for a format whose parser gives no
     * positions and folds `{}` and `[]` into one empty array.
     */
    public static function fromPlain(mixed $value, ?string $locator = null, ?string $authoredExpression = null): self
    {
        if (!\is_array($value)) {
            if ($value !== null && !\is_scalar($value)) {
                throw new LogicException(\sprintf('A configuration value cannot be a %s.', get_debug_type($value)));
            }

            return self::scalar($value, null, $locator, $authoredExpression);
        }

        if ($value === []) {
            return new self(AuthoredShape::EmptyCollection, null, [], null, $locator, $authoredExpression);
        }

        $children = array_map(static fn(mixed $child): self => self::fromPlain($child), $value);

        return array_is_list($value)
            ? self::sequence(array_values($children), null, $locator, $authoredExpression)
            : self::mapping(array_combine(array_map('strval', array_keys($children)), $children), null, $locator, $authoredExpression);
    }

    public function isUnwritten(): bool
    {
        return $this->shape === AuthoredShape::Scalar && $this->scalar === null;
    }

    public function plain(): mixed
    {
        return $this->shape === AuthoredShape::Scalar
            ? $this->scalar
            : array_map(static fn(self $child): mixed => $child->plain(), $this->children);
    }
}
