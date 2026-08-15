<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Parallel\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Parallel\Contract\ParallelConfiguration;
use Qualimetrix\Infrastructure\Parallel\Runtime\ParallelConfigurationStore;

final class ParallelConfigurationStoreTest extends TestCase
{
    #[Test]
    public function itResetsToAutomaticWorkerSelection(): void
    {
        $store = new ParallelConfigurationStore();
        $store->replace(new ParallelConfiguration(4));
        $store->reset();

        self::assertNull($store->current()->workers);
    }
}
