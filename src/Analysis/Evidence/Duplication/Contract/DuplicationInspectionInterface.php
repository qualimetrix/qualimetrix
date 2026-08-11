<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Duplication\Contract;

use SplFileInfo;

interface DuplicationInspectionInterface
{
    public function reset(): void;

    /**
     * @param list<SplFileInfo> $files
     */
    public function inspect(array $files): void;
}
