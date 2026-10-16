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
use Qualimetrix\Analysis\Evidence\Complexity\ComplexityOptions;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;

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
            self::assertCount(1, $e->sources());
            self::assertSame(ConfigurationSource::CommandLine, $e->sources()[0]->source());
            self::assertSame('--rule-opt', $e->sources()[0]->locator());
            self::assertNotNull($e->position());
            self::assertSame('exclude_paths', $e->position()->written);
            self::assertFalse($e->position()->closed);
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
            self::assertCount(1, $e->sources());
            self::assertSame(ConfigurationSource::Resolved, $e->sources()[0]->source());
            self::assertNull($e->sources()[0]->locator());
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
            self::assertCount(1, $e->sources());
            self::assertSame(ConfigurationSource::ConfigFile, $e->sources()[0]->source());
            self::assertSame('/tmp/qmx.yaml', $e->sources()[0]->locator());
            self::assertNotNull($e->position());
            self::assertSame('exclude_paths', $e->position()->written);
            self::assertFalse($e->position()->closed);
        }
    }

    #[Test]
    public function itRefusesARetiredKeyInsideARulesBlockWithTheConfigFileOrigin(): void
    {
        try {
            ResolvedOptionsFixture::document([['source' => 'config', 'values' => ['rules' => ['complexity.ccn' => ['exclude_namespaces' => ['App\\Tests']]]]]], AbsolutePath::fromString('/tmp'), [new RuleMetadata('complexity.ccn', ComplexityOptions::class, '', [], false)]);
            self::fail('The retired rule-option key was accepted.');
        } catch (ConfigurationRefusal $e) {
            self::assertCount(1, $e->sources());
            self::assertSame(ConfigurationSource::ConfigFile, $e->sources()[0]->source());
            self::assertSame('/tmp/qmx.yaml', $e->sources()[0]->locator());
            self::assertNotNull($e->position());
            self::assertSame('exclude_namespaces', $e->position()->written);
            self::assertSame(['rules', 'complexity.ccn', 'exclude_namespaces'], $e->position()->segments);
        }
    }
}
