<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Document\Schema;

use LogicException;

/**
 * What the engine tells an author about one node beyond the form of its
 * value: the likely intent it adds when it refuses a value written there, and
 * the warning it raises when a written empty list replaces a non-empty one.
 * The description is documentation for editors; the engine never reads it.
 */
final readonly class NodeWording
{
    /** @param list<LiteralHint> $literalHints */
    public function __construct(
        public ?string $hint = null,
        public ?string $emptyOverrideNotice = null,
        public array $literalHints = [],
        public ?string $description = null,
    ) {
        $literals = [];
        foreach ($literalHints as $literalHint) {
            if (\in_array($literalHint->literal, $literals, true)) {
                throw new LogicException(\sprintf('Two hints are declared for the literal %s.', var_export($literalHint->literal, true)));
            }
            $literals[] = $literalHint->literal;
        }
    }

    /**
     * This wording with the sentences given replaced; one not given stays.
     *
     * @param ?list<LiteralHint> $literalHints
     */
    public function with(?string $hint = null, ?string $emptyOverrideNotice = null, ?array $literalHints = null, ?string $description = null): self
    {
        return new self(
            $hint ?? $this->hint,
            $emptyOverrideNotice ?? $this->emptyOverrideNotice,
            $literalHints ?? $this->literalHints,
            $description ?? $this->description,
        );
    }

    /**
     * The advice for a refused value: the hint declared for exactly that
     * scalar, otherwise the general hint.
     *
     * @param int|float|string|bool|null $written the refused scalar; null when what was written is not a scalar
     */
    public function adviceFor(int|float|string|bool|null $written): ?string
    {
        foreach ($this->literalHints as $literalHint) {
            if ($literalHint->literal === $written) {
                return $literalHint->sentence;
            }
        }

        return $this->hint;
    }
}
