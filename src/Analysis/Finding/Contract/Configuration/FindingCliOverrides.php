<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Configuration;

final readonly class FindingCliOverrides
{
    /** @param array<string, array<string, mixed>> $options */
    public function __construct(public array $options = []) {}
}
