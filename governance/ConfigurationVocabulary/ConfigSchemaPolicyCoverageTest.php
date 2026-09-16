<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\ConfigurationVocabulary;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\ConfigSchema;

/**
 * Coverage-invariant guard for {@see ConfigSchema::sectionPolicies()}.
 *
 * ADR 0009 requires the policy map to be exhaustive over
 * {@see ConfigSchema::allowedRootKeys()} — every root must declare a
 * normalization policy, and {@see ConfigSchema::policyFor()} must agree with
 * that declared policy exactly.
 */
#[CoversClass(ConfigSchema::class)]
final class ConfigSchemaPolicyCoverageTest extends TestCase
{
    #[Test]
    public function itDeclaresAPolicyForExactlyEveryAllowedRootKey(): void
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
    public function itReturnsTheSamePolicyAsTheSectionPoliciesEntry(): void
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
}
