<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Refusal;

use LogicException;

/**
 * The position within a configuration document that a refusal is addressed to,
 * as its throw site located it.
 *
 * It locates the refused spot for a reader; it is not a round trip to the
 * document's text. A segment can be the key's normalized or schema spelling
 * rather than the author's, and for a required key the author left out the
 * path ends at that key, which was never written.
 */
final readonly class RefusedPosition
{
    /**
     * @param list<string> $segments
     * @param list<string> $accepted
     */
    private function __construct(
        private array $segments,
        private string $written,
        private array $accepted,
        private bool $closed,
    ) {}

    /**
     * A closed position: the list of accepted spellings is exhaustive.
     *
     * @param list<string> $segments path of the key, top to bottom
     * @param string $written the last segment as the throw site received it
     * @param list<string> $accepted must not be empty — an empty list at a closed
     *                               position would mean "nothing is legal here",
     *                               a position that does not exist in the product
     */
    public static function closed(array $segments, string $written, array $accepted): self
    {
        if ($accepted === []) {
            throw new LogicException(
                'A closed position must name at least one accepted spelling; an empty list means "nothing is legal here".',
            );
        }

        return new self($segments, $written, $accepted, true);
    }

    /**
     * An open position: there is no enumerable list by construction (a namespace
     * the user populates with their own names, or a value with a grammar rather
     * than a fixed list). The grammar and accepted words are named by the
     * carrying {@see ConfigurationRefusal}'s `summary()`, not here.
     *
     * @param list<string> $segments
     */
    public static function open(array $segments, string $written): self
    {
        return new self($segments, $written, [], false);
    }

    /** @return list<string> */
    public function segments(): array
    {
        return $this->segments;
    }

    /** The same path for printing, dot-joined. */
    public function display(): string
    {
        return implode('.', $this->segments);
    }

    /**
     * What was rejected, as the throw site holds it: usually the last segment
     * as written, the required key's name when that key is missing, and the
     * schema key when a value of the wrong type is refused.
     */
    public function written(): string
    {
        return $this->written;
    }

    /**
     * Spellings accepted at this position, in the order and spelling the throw
     * site gave them; empty for {@see self::open()}.
     *
     * @return list<string>
     */
    public function accepted(): array
    {
        return $this->accepted;
    }

    /** True for {@see self::closed()}, false for {@see self::open()}. Read, not set independently of the form. */
    public function isClosed(): bool
    {
        return $this->closed;
    }
}
