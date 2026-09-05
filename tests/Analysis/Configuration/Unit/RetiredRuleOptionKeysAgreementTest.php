<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Loader\RetiredRuleOptions;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RetiredRuleOptionKeys;

/**
 * The retired per-rule option family is named twice — once by the config
 * loader, once by the rule layer — because each owns a door the spelling
 * arrives through and neither may import the other. Nothing but this
 * test stops one door from being taught a rename the other never hears, which
 * would leave that spelling refused through one channel and accepted through
 * the other.
 */
final class RetiredRuleOptionKeysAgreementTest extends TestCase
{
    #[Test]
    public function itKeepsBothDoorsNamingTheSameRetiredOptions(): void
    {
        $ruleLayer = RetiredRuleOptionKeys::replacements();
        $loader = RetiredRuleOptions::retiredKeys();
        ksort($ruleLayer);
        ksort($loader);

        self::assertSame($ruleLayer, $loader);
    }
}
