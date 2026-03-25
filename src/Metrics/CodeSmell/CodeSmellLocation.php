<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\CodeSmell;

/**
 * Represents a detected code smell location.
 */
final readonly class CodeSmellLocation
{
    public function __construct(
        public string $type,
        public int $line,
        public int $column,
        public ?string $extra = null,
    ) {}

    /**
     * @return array{type: string, line: int, column: int, extra: ?string}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'line' => $this->line,
            'column' => $this->column,
            'extra' => $this->extra,
        ];
    }
}
