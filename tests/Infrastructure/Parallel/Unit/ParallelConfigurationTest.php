<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Parallel\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Parallel\Contract\ParallelConfiguration;

#[CoversClass(ParallelConfiguration::class)]
final class ParallelConfigurationTest extends TestCase
{
    #[Test]
    public function itRefusesANegativeWorkerCountAtTheValueBoundary(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('parallel.workers must be a non-negative integer.');

        new ParallelConfiguration(-1);
    }
}
