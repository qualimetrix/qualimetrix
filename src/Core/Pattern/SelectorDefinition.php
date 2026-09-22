<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Pattern;

use InvalidArgumentException;

/**
 * One authored selector before it is bound to a path or namespace separator.
 */
final readonly class SelectorDefinition
{
    public const int MAX_PATTERN_LENGTH = 4096;

    public const int MAX_SELECTOR_COUNT = 256;

    /**
     * @throws InvalidArgumentException when the authored value cannot be a selector
     */
    public function __construct(
        public SelectorKind $kind,
        public string $value,
    ) {
        if ($value === '') {
            throw new InvalidArgumentException('Selector value must not be empty');
        }

        if (str_contains($value, "\0")) {
            throw new InvalidArgumentException('Selector value must not contain a NUL byte');
        }

        if (\strlen($value) > self::MAX_PATTERN_LENGTH) {
            throw new InvalidArgumentException(\sprintf(
                'Selector value must not exceed %d bytes',
                self::MAX_PATTERN_LENGTH,
            ));
        }

        if ($kind === SelectorKind::Regex && str_contains($value, '~')) {
            throw new InvalidArgumentException('Regex selector fragments must not contain a raw "~" byte; use "\\x7E" instead');
        }
    }

    /**
     * @throws InvalidArgumentException when the kind is not one of the explicit forms
     */
    public static function fromKindAndValue(string $kind, string $value): self
    {
        $selectorKind = SelectorKind::tryFrom($kind);

        if ($selectorKind === null) {
            throw new InvalidArgumentException(\sprintf(
                'Unknown selector kind "%s"; expected exact, subtree, or regex',
                $kind,
            ));
        }

        return new self($selectorKind, $value);
    }

    /**
     * Stable authored spelling for diagnostics and first-match attribution.
     */
    public function display(): string
    {
        return $this->kind->value . ':' . $this->value;
    }
}
