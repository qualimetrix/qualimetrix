<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Integration\Configuration;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Configuration\ConfigSchema;
use Qualimetrix\Configuration\Loader\SectionNormalizationPolicy;

/**
 * Coverage-invariant guard for {@see ConfigSchema::sectionPolicies()}.
 *
 * ADR 0009 requires the policy map to be exhaustive over
 * {@see ConfigSchema::allowedRootKeys()} — every root must declare a
 * normalization policy. This test pins that invariant so that adding a
 * new root key without registering its policy fails at the test layer
 * before reaching production (where {@see ConfigSchema::policyFor()}
 * would throw {@see LogicException} at runtime).
 */
#[CoversClass(ConfigSchema::class)]
final class ConfigSchemaCoverageTest extends TestCase
{
    #[Test]
    public function sectionPoliciesCoversEveryAllowedRootKeyExactly(): void
    {
        $allowed = ConfigSchema::allowedRootKeys();
        $policyKeys = array_keys(ConfigSchema::sectionPolicies());

        sort($allowed);
        sort($policyKeys);

        self::assertSame(
            $allowed,
            $policyKeys,
            'ConfigSchema::sectionPolicies() must declare a policy for exactly the keys in '
            . 'ConfigSchema::allowedRootKeys(). Either a new root was added without a policy, '
            . 'or a policy entry references an unregistered key (ADR 0009).',
        );
    }

    #[Test]
    public function policyForReturnsTheSamePolicyAsSectionPoliciesEntry(): void
    {
        $policies = ConfigSchema::sectionPolicies();

        foreach (ConfigSchema::allowedRootKeys() as $rootKey) {
            self::assertSame(
                $policies[$rootKey],
                ConfigSchema::policyFor($rootKey),
                'policyFor(' . $rootKey . ') must return the same enum case as sectionPolicies()[' . $rootKey . '].',
            );
        }
    }

    #[Test]
    public function policyForUnregisteredKeyFailsFast(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/sectionPolicies\(\)/');

        ConfigSchema::policyFor('__definitely_not_a_real_root__');
    }

    #[Test]
    public function identifierKeySectionsWrapperDerivesFromSectionPolicies(): void
    {
        $expected = [];
        foreach (ConfigSchema::sectionPolicies() as $key => $policy) {
            if ($policy === SectionNormalizationPolicy::PRESERVE_IMMEDIATE_CHILDREN) {
                $expected[] = $key;
            }
        }

        self::assertSame(
            $expected,
            ConfigSchema::identifierKeySections(),
            'identifierKeySections() must remain a wrapper deriving from sectionPolicies() during the '
            . 'Phase 3 transition (removed in Phase 3.6).',
        );
    }
}
