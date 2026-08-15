<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Cohesion\Contract;

use InvalidArgumentException;

final readonly class LcomCollectionConfiguration
{
    /** @param list<string> $excludedMethods */
    public function __construct(public array $excludedMethods = [])
    {
        if (!array_is_list($excludedMethods) || array_filter($excludedMethods, is_string(...)) !== $excludedMethods) {
            throw new InvalidArgumentException('LCOM excluded methods must be a list of strings.');
        }
    }

    public static function defaults(): self
    {
        return new self();
    }
}
