<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
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
}
