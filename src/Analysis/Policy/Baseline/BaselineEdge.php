<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use InvalidArgumentException;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;

/**
 * The dependency edge a finding carries, as an entry stores it: the target
 * symbol and — when the emitting rule reports one — the kind of reference.
 *
 * The edge is part of an entry's identity (ADR 0017), not decoration. Without it, replacing one forbidden dependency with
 * another leaves the group's count unchanged and the swap is accepted in
 * silence, which would be a regression against the pre-v10 hash rather than
 * a simplification of it.
 *
 * The target is kept as the canonical string
 * ({@see \Qualimetrix\Core\Symbol\SymbolPath::toCanonical()}) rather than as
 * a `SymbolPath`, because that is what the file stores and what the identity
 * is compared on; re-parsing a canonical back into a `SymbolPath` would add
 * a lossy step between two string comparisons.
 */
final readonly class BaselineEdge
{
    public function __construct(
        public string $target,
        public ?DependencyType $type = null,
    ) {
        if ($target === '') {
            throw new InvalidArgumentException('A baseline edge target must not be empty.');
        }
    }

    /**
     * Stable string form, used inside an identity key and for deterministic
     * ordering of the entries under one symbol.
     */
    public function key(): string
    {
        return $this->target . '|' . ($this->type->value ?? '');
    }

    /**
     * The `edge` object of ADR 0017. `type` is omitted rather than written as
     * `null` when the emitting rule reported none — an absent key and a null
     * key would otherwise be two spellings of one fact.
     *
     * @return array{target: string, type?: string}
     */
    public function toArray(): array
    {
        $data = ['target' => $this->target];

        if ($this->type !== null) {
            $data['type'] = $this->type->value;
        }

        return $data;
    }
}
