<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Document\Schema;

final readonly class SectionDeclaration
{
    public function __construct(
        public string $key,
        public NodeSchema $schema,
    ) {}
}
