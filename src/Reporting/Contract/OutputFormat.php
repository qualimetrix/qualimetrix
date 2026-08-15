<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Contract;

use InvalidArgumentException;

final readonly class OutputFormat
{
    public const string DEFAULT = 'summary';

    public function __construct(public string $value = self::DEFAULT)
    {
        if ($value === '') {
            throw new InvalidArgumentException('Output format must not be empty.');
        }
    }
}
