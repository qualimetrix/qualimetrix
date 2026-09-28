<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Reporting\Configuration\OutputFormatResolver;
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

        $resolver = new OutputFormatResolver($formatters);
        self::assertSame('summary', $resolver->resolve(LayeredDocument::of([], AbsolutePath::fromString('/project')))->value);
        self::assertSame('json', $resolver->resolve(LayeredDocument::of([
            ['source' => 'config', 'values' => ['format' => 'text']],
            ['source' => 'cli', 'values' => ['format' => 'json']],
        ], AbsolutePath::fromString('/project')))->value);
    }

    /**
     * A lower value that the winning layer replaces is structurally valid but
     * not executable input. The output resolver therefore judges only the
     * authored winner the run will use.
     */
    #[Test]
    public function itIgnoresAnOverwrittenUnknownFormat(): void
    {
        $resolver = new OutputFormatResolver($this->formatters());

        self::assertSame('json', $resolver->resolve(LayeredDocument::of([
            ['source' => 'config', 'values' => ['format' => 'nope']],
            ['source' => 'cli', 'values' => ['format' => 'json']],
        ], AbsolutePath::fromString('/project')))->value);
    }

    #[Test]
    public function itRefusesAnUnknownWinningFormatAtItsAuthoredPath(): void
    {
        try {
            (new OutputFormatResolver($this->formatters()))->resolve(LayeredDocument::of([
                ['source' => 'preset', 'values' => ['format' => 'json']],
                ['source' => 'qmx.yaml', 'values' => ['format' => 'nope']],
            ], AbsolutePath::fromString('/project')));
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

    private function formatters(): FormatterRegistryInterface
    {
        return new class implements FormatterRegistryInterface {
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
    }
}
