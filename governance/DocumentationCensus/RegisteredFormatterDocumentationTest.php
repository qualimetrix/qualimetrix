<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\DocumentationCensus;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Reporting\Formatter\FormatterRegistryInterface;

/**
 * Health-score documentation must only demonstrate format names that the
 * running formatter registry exposes. This keeps both language variants
 * coupled to the actual CLI output surface rather than to a stale list.
 */
final class RegisteredFormatterDocumentationTest extends TestCase
{
    private static string $projectRoot;

    public static function setUpBeforeClass(): void
    {
        self::$projectRoot = \dirname(__DIR__, 2);
    }

    #[Test]
    public function itUsesRegisteredFormattersInHealthScoreDocumentation(): void
    {
        $container = (new ContainerFactory())->create();
        $formatterRegistry = $container->get(FormatterRegistryInterface::class);
        self::assertInstanceOf(FormatterRegistryInterface::class, $formatterRegistry);

        foreach ([
            'website/docs/reference/health-scores.md',
            'website/docs/reference/health-scores.ru.md',
        ] as $path) {
            $content = $this->readFile($path);
            preg_match_all('/--format=([a-z][a-z-]*)/', $content, $matches);

            self::assertNotEmpty($matches[1], "No --format examples found in {$path}.");

            foreach (array_unique($matches[1]) as $formatterName) {
                self::assertTrue(
                    $formatterRegistry->has($formatterName),
                    "{$path} references unregistered formatter '{$formatterName}'.",
                );
            }
        }
    }

    private function readFile(string $relativePath): string
    {
        $path = self::$projectRoot . '/' . $relativePath;
        self::assertFileExists($path, "Documentation file not found: {$relativePath}");

        $content = file_get_contents($path);
        \assert($content !== false);

        return $content;
    }
}
