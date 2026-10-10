<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Pipeline;

use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;

/** One authored CLI occurrence at its canonical document path. */
final readonly class CommandLinePathWrite
{
    /**
     * @param non-empty-list<string> $path
     * @param array<string|int, mixed>|null $selectorValue
     */
    public function __construct(
        public array $path,
        public string $text,
        public string $optionName,
        public string $authoredExpression,
        public NodeSchema $target,
        public ?array $selectorValue = null,
    ) {}
}
