<?php

declare(strict_types=1);

namespace Fixtures\CouplingProject\Core;

/**
 * Base abstract entity.
 *
 * Expected metrics:
 * - Ca: 2 (User and Order extend this)
 * - Ce: 1 (implements EntityInterface)
 */
abstract class AbstractEntity implements EntityInterface
{
    protected int $id;

    public function getId(): int
    {
        return $this->id;
    }
}
