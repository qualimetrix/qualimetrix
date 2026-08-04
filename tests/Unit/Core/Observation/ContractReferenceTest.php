<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Observation;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Observation\ContractReference;

#[CoversClass(ContractReference::class)]
final class ContractReferenceTest extends TestCase
{
    #[Test]
    public function itDefaultsToVersionOne(): void
    {
        self::assertSame(1, (new ContractReference('complexity.cyclomatic.method'))->version);
    }

    #[Test]
    public function itRejectsAnEmptyId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('id must not be empty');

        new ContractReference('');
    }

    #[Test]
    public function itRejectsANonPositiveVersion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('positive integer');

        new ContractReference('complexity.cyclomatic.method', 0);
    }

    /**
     * The defining property of the identity rule: two references to the same
     * contract at different versions still match. Folding the version into the
     * key would make them fail to match, which downstream reads as "the old
     * finding disappeared and an unrelated one appeared" — mutually exclusive
     * with the intent that a version bump means "no longer comparable".
     */
    #[Test]
    public function itMatchesIdentityAcrossVersions(): void
    {
        $v1 = new ContractReference('design.god-class', 1);
        $v2 = new ContractReference('design.god-class', 2);

        self::assertTrue($v1->matchesIdentity($v2));
        self::assertTrue($v2->matchesIdentity($v1));
    }

    /**
     * The version is a *second* question, asked only once identity matched.
     * Asserted together with the previous test so the ordering — match first,
     * then compare versions — is pinned as behaviour rather than as prose.
     */
    #[Test]
    public function itComparesTheVersionOnlyAfterIdentityMatched(): void
    {
        $v1 = new ContractReference('design.god-class', 1);
        $v2 = new ContractReference('design.god-class', 2);

        self::assertTrue($v1->matchesIdentity($v2), 'identity must survive a version bump');
        self::assertFalse($v1->hasSameVersion($v2), 'the bump must be visible as a version difference');
        self::assertTrue($v1->hasSameVersion(new ContractReference('design.god-class', 1)));
    }

    #[Test]
    public function itDoesNotMatchADifferentContractEvenAtTheSameVersion(): void
    {
        $a = new ContractReference('design.god-class', 3);
        $b = new ContractReference('design.data-class', 3);

        self::assertFalse($a->matchesIdentity($b), 'version equality alone is not identity');
    }

    /**
     * `hasSameVersion()`'s docblock says it is "only meaningful once
     * matchesIdentity() returned true" — enforced here rather than left
     * advisory. §5.4/§5.7 order identity before version deliberately; a
     * caller skipping straight to a version comparison across two unrelated
     * contracts has broken that ordering, and the type now says so instead of
     * silently answering a question it was never meant to answer.
     */
    #[Test]
    public function itRefusesToCompareVersionsBeforeIdentityHasMatched(): void
    {
        $a = new ContractReference('design.god-class', 3);
        $b = new ContractReference('design.data-class', 3);

        self::assertFalse($a->matchesIdentity($b));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('only meaningful once matchesIdentity()');

        $a->hasSameVersion($b);
    }

    #[Test]
    public function itDescribesItselfWithTheVersionForDiagnosticsOnly(): void
    {
        self::assertSame('design.god-class@v3', (new ContractReference('design.god-class', 3))->describe());
    }
}
