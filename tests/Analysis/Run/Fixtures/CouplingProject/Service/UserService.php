<?php

declare(strict_types=1);

namespace Fixtures\CouplingProject\Service;

use Fixtures\CouplingProject\Domain\User;

/**
 * User service.
 *
 * Expected metrics:
 * - Ca: 0 (nobody depends on this)
 * - Ce: 1 (uses User)
 * - Instability: 1.0 (Ce / (Ca + Ce) = 1 / (0 + 1) = 1.0)
 */
class UserService
{
    public function find(int $id): User
    {
        return new User($id, 'Test');
    }
}
