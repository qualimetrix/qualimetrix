<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\DependencyModel\Extraction;

use InvalidArgumentException;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyLocationInterface;
use Qualimetrix\Core\Path\RelativePath;

final readonly class DependencyLocation implements DependencyLocationInterface
{
    public function __construct(
        private RelativePath $file,
        private int $positiveLine,
    ) {
        if ($this->positiveLine < 1) {
            throw new InvalidArgumentException('Dependency location line must be positive');
        }
    }

    public function file(): RelativePath
    {
        return $this->file;
    }

    public function line(): int
    {
        return $this->positiveLine;
    }

    public function toString(): string
    {
        return $this->file->value() . ':' . $this->positiveLine;
    }
}
