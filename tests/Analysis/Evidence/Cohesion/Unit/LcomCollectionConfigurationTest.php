<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Cohesion\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Cohesion\Contract\LcomCollectionConfiguration;

final class LcomCollectionConfigurationTest extends TestCase
{
    #[Test]
    public function itDefaultsToNoExcludedMethods(): void
    {
        self::assertSame([], LcomCollectionConfiguration::defaults()->excludedMethods);
    }

    #[Test]
    public function itRejectsAListContainingNonStrings(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new LcomCollectionConfiguration(['valid', 1]); // @phpstan-ignore argument.type
    }
}
