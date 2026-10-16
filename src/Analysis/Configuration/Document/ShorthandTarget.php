<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Document;

use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;

/** One canonical shorthand destination and the authored placement that wrote it. */
final readonly class ShorthandTarget
{
    /** @param non-empty-list<string> $path */
    public function __construct(
        public array $path,
        public NodeSchema $schema,
        public ResolvedValueInterface $value,
        public ReadingContext $writtenAt,
    ) {}
}
