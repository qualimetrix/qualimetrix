<?php

declare(strict_types=1);

namespace Fixtures\CouplingProject\Domain;

use Fixtures\CouplingProject\Core\AbstractEntity;

/**
 * User entity.
 *
 * Expected metrics:
 * - Ca: 1 (UserService depends on this)
 * - Ce: 1 (extends AbstractEntity)
 */
class User extends AbstractEntity
{
    private string $name;

    public function __construct(int $id, string $name)
    {
        $this->id = $id;
        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
