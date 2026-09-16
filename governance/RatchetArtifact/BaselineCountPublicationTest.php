<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\RatchetArtifact;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use stdClass;

/**
 * The published entry count of the tracked ratchet snapshot (`qmx-baseline.json`)
 * must agree, exactly, with every place documentation states it.
 *
 * Baseline-count publications are derived from the active baseline rather
 * than maintained as independent documentation constants. The inventory is
 * deliberately closed: an added, removed, duplicated, or malformed current
 * publication fails this test.
 */
final class BaselineCountPublicationTest extends TestCase
{
    /**
     * @var list<array{path: string, pattern: string}>
     */
    private const BASELINE_COUNT_PUBLICATIONS = [
        [
            'path' => 'docs/ARCHITECTURE.md',
            'pattern' => '/(?<groups>\d+) groups across (?<subjects>\d+) subjects/',
        ],
    ];

    /** @var list<string> */
    private const SEMANTIC_DOCUMENTATION_ROOTS = ['docs', 'website/docs', 'src'];

    private static string $projectRoot;

    public static function setUpBeforeClass(): void
    {
        self::$projectRoot = \dirname(__DIR__, 2);
    }

    #[Test]
    public function itPublishesTheDerivedBaselineCountTupleExactlyOnce(): void
    {
        $entries = $this->readBaselineEntries();
        $publications = $this->readBaselineCountPublications();

        self::assertSame([], $this->baselineCountPublicationErrors($entries, $publications));
    }

    /**
     * The oracle must reject a baseline-only change while publication content
     * remains untouched. This is an in-memory mutation proof.
     */
    #[Test]
    public function itRejectsBaselineOnlyBaselineCountDrift(): void
    {
        $entries = $this->readBaselineEntries();
        $firstSubject = array_key_first($entries);
        self::assertNotNull($firstSubject, 'The baseline must contain at least one subject.');

        $mutatedEntries = $entries;
        $mutatedEntries[$firstSubject][] = [];

        self::assertNotSame(
            [],
            $this->baselineCountPublicationErrors($mutatedEntries, $this->readBaselineCountPublications()),
        );
    }

    /**
     * The oracle must reject a documentation-only change while the published
     * baseline remains untouched. This is an in-memory mutation proof.
     */
    #[Test]
    public function itRejectsDocumentationOnlyBaselineCountDrift(): void
    {
        $publications = $this->readBaselineCountPublications();
        $architecture = 'docs/ARCHITECTURE.md';
        self::assertArrayHasKey($architecture, $publications);

        $mutatedPublication = preg_replace_callback(
            '/(?<groups>\d+) groups across (?<subjects>\d+) subjects/',
            static fn(array $match): string => ((int) $match['groups'] + 1) . ' groups across ' . $match['subjects'] . ' subjects',
            $publications[$architecture],
            1,
            $replacements,
        );
        self::assertIsString($mutatedPublication);
        $publications[$architecture] = $mutatedPublication;
        self::assertSame(1, $replacements, 'The mutation fixture must alter the sole public baseline tuple.');

        self::assertNotSame(
            [],
            $this->baselineCountPublicationErrors($this->readBaselineEntries(), $publications),
        );
    }

    /**
     * Missing, malformed, and duplicate canonical publications must each fail
     * without mutating repository files.
     */
    #[Test]
    public function itRejectsMissingMalformedAndDuplicateBaselineCountPublications(): void
    {
        $original = $this->readBaselineCountPublications();
        $architecture = 'docs/ARCHITECTURE.md';

        foreach ([
            'missing' => '',
            'malformed' => 'Current baseline groups across subjects.',
            'duplicate' => $original[$architecture] . "\nCurrent baseline 269 groups across 203 subjects.\n",
        ] as $case => $content) {
            $publications = $original;
            $publications[$architecture] = $content;

            self::assertNotSame(
                [],
                $this->baselineCountPublicationErrors($this->readBaselineEntries(), $publications),
                "The {$case} mutation must fail the baseline-count oracle.",
            );
        }
    }

    /**
     * A tuple in any active semantic README or ADR is an extra publication,
     * even when it was not one of the three canonical publication paths.
     */
    #[Test]
    public function itRejectsExtraBaselineCountPublicationsInSemanticReadmeAndAdr(): void
    {
        $original = $this->readBaselineCountPublications();

        foreach ([
            'src/Analysis/Evidence/ComputedMetrics/README.md',
            'docs/adr/0022-capability-oriented-modular-monolith.md',
        ] as $path) {
            self::assertArrayHasKey($path, $original);
            $publications = $original;
            $publications[$path] .= "\nCurrent baseline 269 groups across 203 subjects.\n";

            self::assertNotSame(
                [],
                $this->baselineCountPublicationErrors($this->readBaselineEntries(), $publications),
                "The extra baseline-count tuple in {$path} must fail the oracle.",
            );
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function readBaselineEntries(): array
    {
        $baseline = json_decode($this->readFile('qmx-baseline.json'), false, 512, \JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $baseline, 'qmx-baseline.json must decode to an object.');
        self::assertTrue(property_exists($baseline, 'entries'), 'qmx-baseline.json must contain an entries object.');
        self::assertInstanceOf(stdClass::class, $baseline->entries, 'qmx-baseline.json entries must be an object.');

        $entries = get_object_vars($baseline->entries);

        foreach ($entries as $subject => $groups) {
            self::assertIsArray($groups, "Baseline subject '{$subject}' must contain an array of groups.");
        }

        /** @var array<string, list<mixed>> $entries */
        return $entries;
    }

    /**
     * @return array<string, string>
     */
    private function readBaselineCountPublications(): array
    {
        $publications = [];

        foreach (self::SEMANTIC_DOCUMENTATION_ROOTS as $root) {
            $directory = self::$projectRoot . '/' . $root;
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'md') {
                    continue;
                }

                $path = substr($file->getPathname(), \strlen(self::$projectRoot) + 1);

                if (str_starts_with($path, 'docs/internal/generated/')) {
                    continue;
                }

                $content = file_get_contents($file->getPathname());
                \assert($content !== false);

                if (!$this->isExplicitlyHistoricalDocumentation($content)) {
                    $publications[$path] = $content;
                }
            }
        }

        foreach (['README.md', 'AGENTS.md', 'CHANGELOG.md'] as $path) {
            $content = $this->readFile($path);

            if (!$this->isExplicitlyHistoricalDocumentation($content)) {
                $publications[$path] = $content;
            }
        }

        ksort($publications);

        return $publications;
    }

    private function isExplicitlyHistoricalDocumentation(string $content): bool
    {
        return preg_match('/^>\s+\*\*(?:Historical|Superseded)|^\*\*Status:\*\*\s+Superseded/im', $content) === 1;
    }

    /**
     * @param array<string, list<mixed>> $entries
     * @param array<string, string> $publications
     *
     * @return list<string>
     */
    private function baselineCountPublicationErrors(array $entries, array $publications): array
    {
        $expectedGroups = 0;

        foreach ($entries as $groups) {
            $expectedGroups += \count($groups);
        }
        $expectedSubjects = \count($entries);
        $errors = [];
        $matchedRanges = [];

        foreach (self::BASELINE_COUNT_PUBLICATIONS as ['path' => $path, 'pattern' => $pattern]) {
            $content = $publications[$path] ?? null;

            if (!\is_string($content)) {
                $errors[] = "Missing baseline-count publication {$path}.";
                continue;
            }

            $matches = [];
            $count = preg_match_all($pattern, $content, $matches, \PREG_OFFSET_CAPTURE);

            if ($count !== 1) {
                $errors[] = "Expected one baseline-count tuple for {$path} using {$pattern}; found {$count}.";
                continue;
            }

            $groups = (int) $matches['groups'][0][0];
            $subjects = (int) $matches['subjects'][0][0];
            $matchedRanges[$path][] = [$matches[0][0][1], \strlen($matches[0][0][0])];

            if ($groups !== $expectedGroups || $subjects !== $expectedSubjects) {
                $errors[] = "Baseline-count tuple in {$path} is {$groups}/{$subjects}; expected {$expectedGroups}/{$expectedSubjects}.";
            }
        }

        foreach ($publications as $path => $content) {
            preg_match_all('/\b\d+(?:\s+active)?\s+baseline\s+groups\s+(?:across|\/)\s+\d+\s+subjects\b|\b\d+\s+groups\s+across\s+\d+\s+subjects\b|\bactive\s+baseline\s+\d+\s+groups\s*\/\s*\d+\s+subjects\b/', $content, $matches, \PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as [$match, $offset]) {
                $isExpected = false;

                foreach ($matchedRanges[$path] ?? [] as [$expectedOffset, $expectedLength]) {
                    if ($offset === $expectedOffset && \strlen($match) === $expectedLength) {
                        $isExpected = true;
                        break;
                    }
                }

                if (!$isExpected) {
                    $errors[] = "Unexpected baseline-count publication in {$path}: {$match}.";
                }
            }
        }

        return $errors;
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
