<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\Unit\FindingProjection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerPolicyPreparationInterface;
use Qualimetrix\Reporting\FindingProjection\DeclaredChannelFileScope;

/**
 * What the assembled scope answers, against channel names written out here.
 *
 * The roll-call — that every capability declaring project-scoped channels is
 * in the assembly at all — is read off the tree by
 * {@see \Qualimetrix\Governance\Channel\ProjectScopedChannelRollCallTest}.
 * It cannot be stated here: listing the declaring capabilities in this file
 * would be the same list the assembly carries, and the omission it exists to
 * catch would be edited into both at once.
 */
#[CoversClass(DeclaredChannelFileScope::class)]
final class DeclaredChannelFileScopeTest extends TestCase
{
    #[Test]
    public function itMarksAChannelBothCapabilitiesDeclareAsProjectScoped(): void
    {
        $scope = DeclaredChannelFileScope::create();

        self::assertFalse($scope->isFileScoped(new FindingChannel('architecture.layer-violation')));
        self::assertFalse($scope->isFileScoped(new FindingChannel('architecture.coverage-gap')));
        self::assertFalse($scope->isFileScoped(new FindingChannel('architecture.circular-dependency')));
    }

    #[Test]
    public function itLeavesAnUndeclaredChannelFileScoped(): void
    {
        $scope = DeclaredChannelFileScope::create();

        self::assertTrue($scope->isFileScoped(new FindingChannel('health.cohesion')));
        self::assertTrue($scope->isFileScoped(new FindingChannel('coupling.cbo')));
        // A dotted descendant of a declared channel is a different channel and
        // inherits nothing.
        self::assertTrue($scope->isFileScoped(new FindingChannel(
            LayerPolicyPreparationInterface::PRODUCER_RULE_NAME . '.invented',
        )));
    }
}
