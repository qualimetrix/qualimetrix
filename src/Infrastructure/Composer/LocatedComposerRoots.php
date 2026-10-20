<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Composer;

use Qualimetrix\Infrastructure\Composer\Contract\ComposerRootOmission;

final readonly class LocatedComposerRoots
{
    /**
     * @param list<string> $roots
     * @param list<ComposerRootOmission> $omissions
     */
    public function __construct(public array $roots, public array $omissions) {}
}
