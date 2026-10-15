<?php

declare(strict_types=1);

namespace Qualimetrix\Core\FileTarget\PrivateGroup;

interface PrivateGroupMembership
{
    public function isPrivatePrimaryGroup(int $effectiveUid, int $groupId): bool;
}
