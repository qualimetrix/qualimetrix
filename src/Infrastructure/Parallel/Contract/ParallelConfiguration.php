<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Parallel\Contract;

use InvalidArgumentException;

final readonly class ParallelConfiguration
{
    public function __construct(public ?int $workers = null)
    {
        $refusal = $workers === null ? null : self::workerCountRefusal($workers);
        if ($refusal !== null) {
            throw new InvalidArgumentException($refusal);
        }
    }

    /** The worker-count grammar shared by document validation and this value boundary. */
    public static function workerCountRefusal(int $workers): ?string
    {
        return $workers < 0 ? 'parallel.workers must be a non-negative integer.' : null;
    }
}
