<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\ModularOwnership;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerDeclarationValidator;

final class LayerDeclarationValidatorChannelCensusTest extends TestCase
{
    /** The five declaration verdicts stayed five: nothing moved into the validator. */
    #[Test]
    public function itLeavesTheDeclarationValidatorWithItsFiveChannels(): void
    {
        self::assertSame(
            [
                'architecture.coverage-gap',
                'architecture.unreachable-layer',
                'architecture.potential-shadow',
                'architecture.empty-template',
                'architecture.pending-layer-matched',
            ],
            array_keys(LayerDeclarationValidator::channelDeclarations()),
        );
    }
}
