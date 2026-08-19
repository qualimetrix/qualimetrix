<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\FindingProjection\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CircularDependency\Contract\CircularDependencyPreparationInterface;
use Qualimetrix\Analysis\Finding\Contract\ViolationChannel;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerPolicyPreparationInterface;
use Qualimetrix\Reporting\FindingProjection\DeclaredChannelFileScope;

/**
 * The roll-call is complete: every capability that declares project-scoped
 * channels is actually asked.
 *
 * A capability could publish `PROJECT_SCOPED_CHANNELS` and be silently left
 * out of the assembly, which would restore the exclusion bypass this whole
 * mechanism exists to close — and no filter test would notice, because a
 * filter test builds the scope from the declarations directly.
 */
#[CoversClass(DeclaredChannelFileScope::class)]
final class DeclaredChannelFileScopeTest extends TestCase
{
    #[Test]
    public function itMarksEveryDeclaredChannelAsProjectScoped(): void
    {
        $scope = DeclaredChannelFileScope::create();

        foreach (self::declaredKeys() as $key) {
            self::assertFalse(
                $scope->isFileScoped(ViolationChannel::fromKey($key)),
                \sprintf('%s is declared project-scoped but the assembled scope does not say so', $key),
            );
        }
    }

    #[Test]
    public function itLeavesAnUndeclaredChannelFileScoped(): void
    {
        $scope = DeclaredChannelFileScope::create();

        self::assertTrue($scope->isFileScoped(new ViolationChannel('computed.health', 'health.cohesion')));
        self::assertTrue($scope->isFileScoped(new ViolationChannel('coupling.cbo', 'coupling.cbo.class')));
        // A dotted descendant of a declared channel is a different channel and
        // inherits nothing.
        self::assertTrue($scope->isFileScoped(new ViolationChannel(
            LayerPolicyPreparationInterface::PRODUCER_RULE_NAME,
            LayerPolicyPreparationInterface::PRODUCER_RULE_NAME . '.invented',
        )));
    }

    /** @return list<string> */
    private static function declaredKeys(): array
    {
        return [
            ...LayerPolicyPreparationInterface::PROJECT_SCOPED_CHANNELS,
            ...CircularDependencyPreparationInterface::PROJECT_SCOPED_CHANNELS,
        ];
    }
}
