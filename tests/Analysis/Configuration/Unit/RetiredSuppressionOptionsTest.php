<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\RetiredSuppressionOptions;

/**
 * The three refusal doors of {@see RetiredSuppressionOptions} as carrier
 * sites, direct rather than through a caller: `refuseInRules()` and
 * `refuseRootKey()` are already exercised end-to-end through
 * {@see \Qualimetrix\Analysis\Configuration\Loader\YamlConfigLoader}
 * (`YamlConfigLoaderTest::itRefusesARetiredRootOptionInTheSpellingItsAuthorUsed()`
 * and `itRefusesARetiredRuleOptionInTheSpellingItsAuthorUsed()`), but nothing
 * exercises `refuseRuleOption()`'s own default origin — the `--rule-opt` door
 * — because its only production caller,
 * `RuleOptionsParser::parseRuleOption()`, sits outside this package's file
 * set. `RuleOptionsFactory` (this package's own caller) always passes an
 * explicit {@see ConfigurationSource::Resolved} origin, so the default branch
 * needs a test that calls the door directly.
 */
#[CoversClass(RetiredSuppressionOptions::class)]
final class RetiredSuppressionOptionsTest extends TestCase
{
    #[Test]
    public function itRefusesARetiredCliOptionWithTheRuleOptDoorAsItsDefaultOrigin(): void
    {
        try {
            RetiredSuppressionOptions::refuseRuleOption(['exclude_paths' => null]);
            self::fail('The retired spelling was accepted.');
        } catch (ConfigurationRefusal $e) {
            self::assertStringContainsString('The "exclude_paths" option was retired', $e->getMessage());
            self::assertSame(ConfigurationSource::CommandLine, $e->origin()->source());
            self::assertSame('--rule-opt', $e->origin()->locator());
            self::assertNotNull($e->position());
            self::assertSame('exclude_paths', $e->position()->written());
            self::assertFalse($e->position()->isClosed());
        }
    }

    #[Test]
    public function itRefusesARetiredCliOptionUnderAnExplicitlyPassedOrigin(): void
    {
        $origin = ConfigurationOrigin::of(ConfigurationSource::Resolved);

        try {
            RetiredSuppressionOptions::refuseRuleOption(['excludeNamespaces' => null], $origin);
            self::fail('The retired spelling was accepted.');
        } catch (ConfigurationRefusal $e) {
            self::assertSame(ConfigurationSource::Resolved, $e->origin()->source());
            self::assertNull($e->origin()->locator());
        }
    }

    #[Test]
    public function itAcceptsAConfigNotNamingAnyRetiredOption(): void
    {
        $this->expectNotToPerformAssertions();

        RetiredSuppressionOptions::refuseRuleOption(['suppress_paths' => ['src/Legacy/**']]);
    }

    #[Test]
    public function itRefusesARetiredRootKeyWithTheConfigFileOriginAndAnOpenPosition(): void
    {
        try {
            RetiredSuppressionOptions::refuseRootKey(
                ['excludePaths'],
                '/tmp/qmx.yaml',
                ['excludePaths' => 'exclude_paths'],
            );
            self::fail('The retired root key was accepted.');
        } catch (ConfigurationRefusal $e) {
            self::assertSame(ConfigurationSource::ConfigFile, $e->origin()->source());
            self::assertSame('/tmp/qmx.yaml', $e->origin()->locator());
            self::assertNotNull($e->position());
            self::assertSame('exclude_paths', $e->position()->written());
            self::assertFalse($e->position()->isClosed());
        }
    }

    #[Test]
    public function itRefusesARetiredKeyInsideARulesBlockWithTheConfigFileOrigin(): void
    {
        try {
            RetiredSuppressionOptions::refuseInRules(
                ['rules' => [['exclude_namespaces' => ['App\\Tests']]]],
                'rules',
                '/tmp/qmx.yaml',
            );
            self::fail('The retired rule-option key was accepted.');
        } catch (ConfigurationRefusal $e) {
            self::assertSame(ConfigurationSource::ConfigFile, $e->origin()->source());
            self::assertSame('/tmp/qmx.yaml', $e->origin()->locator());
            self::assertNotNull($e->position());
            self::assertSame('exclude_namespaces', $e->position()->written());
            self::assertSame(['rules', 'exclude_namespaces'], $e->position()->segments());
        }
    }
}
