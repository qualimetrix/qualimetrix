<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Reporting\Configuration\OutputFormatResolver;
use Qualimetrix\Reporting\Formatter\FormatterInterface;
use Qualimetrix\Reporting\Formatter\FormatterRegistryInterface;

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
        self::assertSame('summary', $resolver->resolve(new ConfigurationDocument([], AbsolutePath::fromString('/project')))->value);
        self::assertSame('json', $resolver->resolve(new ConfigurationDocument([
            ['source' => 'config', 'values' => ['format' => 'text']],
            ['source' => 'cli', 'values' => ['format' => 'json']],
        ], AbsolutePath::fromString('/project')))->value);
    }

    /**
     * Every contribution is judged, not only the winning one: a value nobody
     * will use is still a value somebody wrote, and answering it only when it
     * happens to win makes the same typo silent or fatal depending on what
     * else was passed.
     */
    #[Test]
    public function itRefusesAnUnknownFormatInTheFileEvenWhenTheCommandLineOverridesIt(): void
    {
        $resolver = new OutputFormatResolver($this->formatters());

        self::expectException(ConfigurationRefusal::class);
        $resolver->resolve(new ConfigurationDocument([
            ['source' => 'config', 'values' => ['format' => 'nope']],
            ['source' => 'cli', 'values' => ['format' => 'json']],
        ], AbsolutePath::fromString('/project')));
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
