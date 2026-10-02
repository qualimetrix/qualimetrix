<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Inline\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Complexity\WmcRule;
use Qualimetrix\Analysis\Policy\Inline\Contract\RuleValidatorMapFactory;

#[CoversClass(RuleValidatorMapFactory::class)]
final class RuleValidatorMapFactoryTest extends TestCase
{
    #[Test]
    public function itRefusesAMissingRuleClassBeforeBuildingTheMap(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Nope\\MissingRule');

        /** @phpstan-ignore argument.type (deliberately passing an unloadable rule class) */
        RuleValidatorMapFactory::build(['Nope\\MissingRule', WmcRule::class]);
    }
}
