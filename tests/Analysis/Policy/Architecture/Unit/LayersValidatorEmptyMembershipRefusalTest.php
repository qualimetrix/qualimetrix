<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\LayersValidator;

final class LayersValidatorEmptyMembershipRefusalTest extends TestCase
{
    #[Test]
    public function itLetsEmptyMembershipCriteriaReachTheConfigurationCarrier(): void
    {
        $validator = new LayersValidator();

        try {
            $validator->validate([
                ['name' => 'empty-layer'],
            ]);
            self::fail('Expected ConfigurationRefusal for a layer entry declaring no criterion.');
        } catch (ConfigurationRefusal $e) {
            self::assertStringContainsString(
                'must declare at least one of "patterns", "suffix", "attributes", "implements" or "extends"',
                $e->getMessage(),
            );
        }
    }
}
