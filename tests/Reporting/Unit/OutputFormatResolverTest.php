<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\SectionDeclaration;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Reporting\Configuration\OutputFormatResolver;
use Qualimetrix\Reporting\Configuration\OutputFormatSection;
use Qualimetrix\Reporting\Configuration\OutputFormatVocabulary;
use Qualimetrix\Reporting\Formatter\FormatterInterface;
use Qualimetrix\Reporting\Formatter\FormatterRegistryInterface;
use Qualimetrix\Tests\Analysis\Configuration\Support\LayeredDocument;

final class OutputFormatResolverTest extends TestCase
{
    #[Test]
    public function itUsesSummaryByDefaultAndTheLastExplicitFormat(): void
    {
        $formatters = new class implements FormatterRegistryInterface {
            public function get(string $name): FormatterInterface
            {
                throw new LogicException('not used');
            }

            public function has(string $name): bool
            {
                return \in_array($name, $this->getAvailableNames(), true);
            }

            public function getAvailableNames(): array
            {
                return ['json', 'summary', 'text'];
            }

            public function declaredFormatOptionKeys(): array
            {
                return [];
            }
        };

        $vocabulary = new OutputFormatVocabulary($formatters);
        $resolver = new OutputFormatResolver($vocabulary);
        $section = new OutputFormatSection($vocabulary);
        self::assertSame('summary', $resolver->resolve(LayeredDocument::of([], AbsolutePath::fromString('/project'), $section))->value);
        self::assertSame('json', $resolver->resolve(LayeredDocument::of([
            ['source' => 'config', 'values' => ['format' => 'text']],
            ['source' => 'cli', 'values' => ['format' => 'json']],
        ], AbsolutePath::fromString('/project'), $section))->value);
    }

    #[Test]
    public function itRefusesAnUnknownFormatInTheFileEvenWhenTheCommandLineOverridesIt(): void
    {
        $vocabulary = new OutputFormatVocabulary($this->formatters());
        $resolver = new OutputFormatResolver($vocabulary);

        try {
            $resolver->resolve(LayeredDocument::of([
                ['source' => 'qmx.yaml', 'values' => ['format' => 'nope']],
                ['source' => 'cli', 'values' => ['format' => 'json']],
            ], AbsolutePath::fromString('/project'), new OutputFormatSection($vocabulary)));
            self::fail('An unknown lower-layer output format was accepted.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame('qmx.yaml', $refusal->sources()[0]->locator());
            self::assertSame(ConfigurationSource::ConfigFile, $refusal->sources()[0]->source());
            self::assertSame(['format'], $refusal->position()?->segments);
            self::assertStringContainsString('Output format "nope" is not one of', $refusal->summary());
        }
    }

    #[Test]
    public function itRefusesAnUnknownWinningFormatAtItsAuthoredPath(): void
    {
        try {
            $vocabulary = new OutputFormatVocabulary($this->formatters());
            $resolver = new OutputFormatResolver($vocabulary);
            $resolver->resolve(LayeredDocument::of([
                ['source' => 'preset', 'values' => ['format' => 'json']],
                ['source' => 'qmx.yaml', 'values' => ['format' => 'nope']],
            ], AbsolutePath::fromString('/project'), new OutputFormatSection($vocabulary)));
            self::fail('An unknown winning output format was accepted.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame(
                [ConfigurationSource::ConfigFile],
                array_map(static fn(ConfigurationOrigin $origin): ConfigurationSource => $origin->source(), $refusal->sources()),
            );
            self::assertSame('qmx.yaml', $refusal->sources()[0]->locator());
            self::assertSame(['format'], $refusal->position()?->segments);
        }
    }

    #[Test]
    public function itValidatesTheWinningFormatAgainstTheRegistryWhenTheSectionHasNoDictionaryJudge(): void
    {
        $vocabulary = new OutputFormatVocabulary($this->formatters());
        $resolver = new OutputFormatResolver($vocabulary);
        $plainStringSection = new class implements DocumentSectionSchemaInterface {
            public function declaration(): SectionDeclaration
            {
                return new SectionDeclaration('format', NodeSchema::scalar(ScalarForm::String));
            }
        };

        try {
            $resolver->resolve(LayeredDocument::of([
                ['source' => 'preset', 'values' => ['format' => 'json']],
                ['source' => 'qmx.yaml', 'values' => ['format' => 'nope']],
            ], AbsolutePath::fromString('/project'), $plainStringSection));
            self::fail('An unknown winning output format was accepted without a per-layer dictionary judge.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame([ConfigurationSource::ConfigFile], array_map(
                static fn(ConfigurationOrigin $origin): ConfigurationSource => $origin->source(),
                $refusal->sources(),
            ));
            self::assertSame('qmx.yaml', $refusal->sources()[0]->locator());
            self::assertSame(['format'], $refusal->position()?->segments);
            self::assertSame('Output format "nope" is not one of: json, summary, text.', $refusal->summary());
        }

        $document = LayeredDocument::of([
            ['source' => 'qmx.yaml', 'values' => ['format' => 'text-verbose']],
        ], AbsolutePath::fromString('/project'), $plainStringSection);
        self::assertSame('text-verbose', $resolver->resolve($document)->value);
    }

    private function formatters(): FormatterRegistryInterface
    {
        return new class implements FormatterRegistryInterface {
            public function get(string $name): FormatterInterface
            {
                throw new LogicException('not used');
            }

            public function has(string $name): bool
            {
                return \in_array($name, [...$this->getAvailableNames(), 'text-verbose'], true);
            }

            public function getAvailableNames(): array
            {
                return ['json', 'summary', 'text'];
            }

            public function declaredFormatOptionKeys(): array
            {
                return [];
            }
        };
    }
}
