<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Cohesion\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\CommandLinePathWrite;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Evidence\Cohesion\LcomExcludedMethods;
use Qualimetrix\Analysis\Evidence\Cohesion\LcomOptions;
use Qualimetrix\Analysis\Evidence\Cohesion\LcomRule;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ConfiguredValueWriters;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\RuleOptionSurface;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;
use Qualimetrix\Analysis\Finding\RuleConfiguration\OptionForms\RuleOptionDocumentForms;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;

#[CoversClass(LcomExcludedMethods::class)]
#[CoversClass(LcomOptions::class)]
final class LcomExcludedMethodsWritersTest extends TestCase
{
    #[Test]
    public function itCarriesTheFileThatWroteTheListBuiltFromADocument(): void
    {
        $options = self::optionsFromDocument(['rules' => ['cohesion.lcom' => ['exclude-methods' => ['nope']]]]);

        self::assertSame('Written by configuration file "/project/qmx.yaml".', $options->excludeMethodsWriters?->sentence());
    }

    #[Test]
    public function itCarriesTheOptionThatWroteTheList(): void
    {
        $surface = RuleOptionSurface::of(LcomOptions::class);
        $address = $surface->locate('exclude-methods') ?? self::fail('The option must have a declared address.');
        $write = new CommandLinePathWrite(
            ['rules', 'cohesion.lcom', $address->key],
            '',
            '--lcom-exclude-methods',
            '--lcom-exclude-methods=nope',
            (new RuleOptionDocumentForms())->schemaAt($surface, $address),
            ['nope'],
        );

        $options = self::optionsFromDocument([], [$write]);

        self::assertSame(['nope'], $options->excludeMethods);
        self::assertSame('Written by option --lcom-exclude-methods (written as --lcom-exclude-methods=nope).', $options->excludeMethodsWriters?->sentence());
    }

    #[Test]
    public function itNamesNoWritersWhenTheListWasNotWritten(): void
    {
        self::assertNull(self::optionsFromDocument(['rules' => ['cohesion.lcom' => ['enabled' => true]]])->excludeMethodsWriters);
        self::assertNull((new LcomOptions(excludeMethods: ['nope']))->excludeMethodsWriters);
    }

    #[Test]
    public function itKeepsTheWritersThroughAThresholdOverride(): void
    {
        $options = self::optionsFromDocument(['rules' => ['cohesion.lcom' => ['exclude-methods' => ['nope']]]]);

        $overridden = $options->withOverride(4, 6);

        self::assertEquals($options->excludeMethodsWriters, $overridden->excludeMethodsWriters);
        self::assertNotNull($overridden->excludeMethodsWriters);
    }

    #[Test]
    public function itEndsTheFindingWithItsWritersWithoutMovingItsKey(): void
    {
        $root = ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'qmx.yaml');
        $imported = ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'vendor/acme/std/base.yaml')->importedThrough($root);

        $fromRoot = $this->only(new LcomOptions(excludeMethods: ['Nope'], excludeMethodsWriters: new ConfiguredValueWriters([$root])));
        $fromImport = $this->only(new LcomOptions(excludeMethods: ['Nope'], excludeMethodsWriters: new ConfiguredValueWriters([$imported])));
        $unknown = $this->only(new LcomOptions(excludeMethods: ['Nope']));

        self::assertSame('The exclude_methods name "Nope" matched no method declared in this project. Written by configuration file "qmx.yaml".', $fromRoot->message);
        self::assertStringEndsWith('Written by configuration file "vendor/acme/std/base.yaml" (imported by configuration file "qmx.yaml").', $fromImport->message);
        self::assertSame('The exclude_methods name "Nope" matched no method declared in this project.', $unknown->message);
        self::assertSame($fromRoot->occurrenceKey?->value, $fromImport->occurrenceKey?->value);
        self::assertSame($fromRoot->occurrenceKey?->value, $unknown->occurrenceKey?->value);
    }

    private function only(LcomOptions $options): Finding
    {
        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')->willReturn([]);

        $findings = LcomExcludedMethods::findings(
            new AnalysisContext($repository),
            $options,
            LcomRule::NAME,
            LcomRule::channelDeclarations()['cohesion.unmatched-exclude-method'],
        );
        self::assertCount(1, $findings);

        return $findings[0];
    }

    /**
     * @param array<string, mixed> $fileValues
     * @param list<CommandLinePathWrite>|null $cliPathWrites
     */
    private static function optionsFromDocument(array $fileValues, ?array $cliPathWrites = null): LcomOptions
    {
        $metadata = [new RuleMetadata('cohesion.lcom', LcomOptions::class, '', [], false)];
        $configuration = ResolvedOptionsFixture::authoredConfiguration($fileValues, $metadata, $cliPathWrites);
        $options = ResolvedOptionsFixture::build($configuration, $metadata)->for('cohesion.lcom');
        self::assertInstanceOf(LcomOptions::class, $options);

        return $options;
    }
}
