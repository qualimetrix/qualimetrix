<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\SelectorSyntax;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SelectorSurfaceRegistryTest extends TestCase
{
    private const string REGISTRY = 'governance/SelectorSyntax/Fixtures/selector-surfaces.tsv';

    /** @var list<string> */
    private const array LANGUAGES = [
        'explicit-selector',
        'architecture-binding-dsl',
        'closed-identity',
    ];

    #[Test]
    public function itRegistersEverySurfaceWithResolvableEvidence(): void
    {
        $root = \dirname(__DIR__, 2);
        $rows = self::rows($root);
        $surfaces = [];

        foreach ($rows as $row) {
            self::assertNotSame('', $row['surface']);
            self::assertArrayNotHasKey($row['surface'], $surfaces, 'Duplicate selector surface: ' . $row['surface']);
            self::assertContains($row['language'], self::LANGUAGES, $row['surface']);
            self::assertNotSame('', $row['universe'], $row['surface']);
            self::assertNotSame('', $row['owner'], $row['surface']);

            foreach (['ingress', 'matcher', 'consumer'] as $column) {
                self::assertFileExists($root . '/' . $row[$column], $row['surface'] . ' has stale ' . $column . ' evidence');
            }

            if ($row['language'] !== 'explicit-selector') {
                self::assertNotSame('-', $row['exception'], $row['surface'] . ' must explain why it is an exception');
            }

            $surfaces[$row['surface']] = true;
        }

        self::assertGreaterThanOrEqual(20, \count($surfaces), 'The registry unexpectedly lost selector doors.');
    }

    /** @return list<array<string, string>> */
    private static function rows(string $root): array
    {
        $handle = fopen($root . '/' . self::REGISTRY, 'rb');
        self::assertIsResource($handle);
        $header = fgetcsv($handle, separator: "\t", escape: '');
        self::assertSame(['surface', 'universe', 'owner', 'language', 'ingress', 'matcher', 'consumer', 'exception'], $header);
        $rows = [];

        while (($values = fgetcsv($handle, separator: "\t", escape: '')) !== false) {
            self::assertCount(\count($header), $values, 'Malformed selector registry row.');
            $row = [];
            foreach ($header as $index => $column) {
                $value = $values[$index];
                if (!\is_string($value)) {
                    self::fail('Selector registry values must be strings.');
                }
                $row[$column] = $value;
            }
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }
}
