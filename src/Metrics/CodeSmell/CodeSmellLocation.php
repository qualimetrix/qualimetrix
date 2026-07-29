<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\CodeSmell;

/**
 * Represents a detected code smell location.
 */
final readonly class CodeSmellLocation
{
    /**
     * @param ?bool $promoted For `boolean_argument` entries: whether the parameter is a
     *                        promoted constructor property (`public bool $x`) rather than
     *                        a plain method/function argument. `null` for smell types that
     *                        don't distinguish the two (i.e. everything except
     *                        `boolean_argument`).
     */
    public function __construct(
        public string $type,
        public int $line,
        public int $column,
        public ?string $extra = null,
        public ?bool $promoted = null,
    ) {}

    /**
     * @return array{type: string, line: int, column: int, extra: ?string, promoted: ?bool}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'line' => $this->line,
            'column' => $this->column,
            'extra' => $this->extra,
            'promoted' => $this->promoted,
        ];
    }
}
