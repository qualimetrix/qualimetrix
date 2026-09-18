<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\ConfigSchema;

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
    public function itFailsFastForAnUnregisteredKey(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/sectionPolicies\(\)/');

        ConfigSchema::policyFor('__definitely_not_a_real_root__');
    }
}
