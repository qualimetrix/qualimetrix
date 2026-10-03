<?php

declare(strict_types=1);

namespace Qualimetrix\PromiseEffect\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PromiseEffectP1Set;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionShape;
use ReflectionMethod;

final class P1DeclarationHalvesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__, 3) . '/scripts/promise-effect-p1-set.php';
    }

    #[Test]
    public function itSeparatesOrdinaryAcceptedAndAnsweredKeysFromClassValidatedKeys(): void
    {
        $options = new class {
            public static function acceptedOptionKeys(): RuleOptionKeySet
            {
                return RuleOptionKeySet::of(['plain-key' => RuleOptionShape::text()])
                    ->alsoAcceptedAndValidatedByTheClass('validated-key', RuleOptionShape::text())
                    ->alsoAnsweredByTheClass('answered-key');
            }
        };
        $halves = new ReflectionMethod(PromiseEffectP1Set::class, 'halves')
            ->invoke(new PromiseEffectP1Set(), $options::class);

        self::assertSame(['accepted' => ['plain-key'], 'answered' => ['answered-key']], $halves);
    }
}
