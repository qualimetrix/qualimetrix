<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Integration\Documentation;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Rule\CliAliasReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleDefinitionInterface;
use Qualimetrix\Analysis\Finding\Rule\RuleInterface;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Reporting\Formatter\FormatterRegistryInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use stdClass;
use Symfony\Component\Yaml\Yaml;

/**
 * Validates that documentation stays in sync with source code.
 *
 * These tests catch stale documentation automatically in CI:
 * - Rule names missing from default-thresholds.md
 * - CLI aliases missing from Analysis/Configuration/README.md
 * - YAML examples in README.md that don't parse or reference non-existent rules
 */
final class DocumentationConsistencyTest extends TestCase
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

    private const PLAN_LOCAL_REFERENCE_PATTERN = '~(?<![A-Za-z0-9_.-])(?:PLAN\.md|03-cure\.md|01-freeze-kind\.md|followups/c2\.md)\b~';

    private const PACKAGE_CHRONOLOGY_PATTERN = '~(?<![A-Za-z0-9_,])[XP\x{0420}\x{0425}\x{0428}]\d+[A-Za-z\x{0410}-\x{044F}0-9.-]*(?![A-Za-z0-9_])~u';

    private static string $projectRoot;

    public static function setUpBeforeClass(): void
    {
        self::$projectRoot = \dirname(__DIR__, 4);
    }

    #[Test]
    public function itIndexesEveryActivePlanDirectory(): void
    {
        $plansRoot = self::$projectRoot . '/docs/internal/plans';
        $directories = [];

        foreach (new FilesystemIterator($plansRoot, FilesystemIterator::SKIP_DOTS) as $entry) {
            if (!$entry instanceof SplFileInfo) {
                continue;
            }

            if ($entry->isDir()) {
                $directories[] = $entry->getBasename();
            }
        }

        sort($directories);
        $index = $this->readFile('docs/internal/plans/README.md');
        preg_match_all('~\]\(([^/)]+)/~', $index, $matches);
        $indexed = array_values(array_unique($matches[1]));
        sort($indexed);

        self::assertSame($directories, $indexed, 'The plan index must list every active plan directory exactly once.');
    }

    #[Test]
    public function itKeepsExecutableSourcesIndependentFromPlanningRecords(): void
    {
        $roots = ['bin', 'scripts', 'src', 'tests'];
        $dependencies = [];
        $self = __FILE__;
        $patterns = [
            'concrete plan path' => '~docs/internal/plans/(?!README\.md\b)[A-Za-z0-9._/-]+~',
            'plan-local filename' => self::PLAN_LOCAL_REFERENCE_PATTERN,
        ];

        foreach ($roots as $root) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
                self::$projectRoot . '/' . $root,
                FilesystemIterator::SKIP_DOTS,
            ));

            foreach ($iterator as $file) {
                if (!$file->isFile() || !\in_array($file->getExtension(), ['md', 'php', 'py', 'js', 'mjs', 'json', 'yaml', 'yml', 'tsv'], true)) {
                    continue;
                }

                if ($file->getPathname() === $self
                    || str_contains($file->getPathname(), '/node_modules/')
                    || str_contains($file->getPathname(), '/vendor/')
                    || str_contains($file->getPathname(), '/dist/')) {
                    continue;
                }

                $content = file_get_contents($file->getPathname());
                \assert($content !== false);
                $relativePath = substr($file->getPathname(), \strlen(self::$projectRoot) + 1);

                foreach ($patterns as $kind => $pattern) {
                    preg_match_all($pattern, $content, $matches, \PREG_OFFSET_CAPTURE);

                    foreach ($matches[0] as [$match, $offset]) {
                        $line = substr_count(substr($content, 0, $offset), "\n") + 1;
                        $dependencies[] = "{$relativePath}:{$line}: {$kind}: {$match}";
                    }
                }

                $commentContent = self::commentContent($file->getExtension(), $content);
                preg_match_all(self::PACKAGE_CHRONOLOGY_PATTERN, $commentContent, $matches);

                foreach ($matches[0] as $match) {
                    $dependencies[] = "{$relativePath}: package chronology in comment: {$match}";
                }
            }
        }

        self::assertSame(
            [],
            array_values(array_unique($dependencies)),
            "Executable sources depend on planning records or package chronology:\n" . implode("\n", $dependencies),
        );
    }

    #[Test]
    public function itRecognizesPlanningChronologyOnlyInsideComments(): void
    {
        self::assertSame('', self::commentContent('js', 'const X12 = 1;'));
        self::assertSame('', self::commentContent('js', 'const marker = "// P5 is data";'));
        self::assertMatchesRegularExpression(
            self::PACKAGE_CHRONOLOGY_PATTERN,
            self::commentContent('js', '// P5 was the implementation package.'),
        );
        self::assertMatchesRegularExpression(
            self::PACKAGE_CHRONOLOGY_PATTERN,
            self::commentContent('py', "# \u{0420}5 was the implementation package."),
        );
        self::assertMatchesRegularExpression(
            self::PACKAGE_CHRONOLOGY_PATTERN,
            self::commentContent('py', 'value = 1 # P5 was the implementation package.'),
        );
        self::assertMatchesRegularExpression(self::PLAN_LOCAL_REFERENCE_PATTERN, 'See 01-freeze-kind.md.');
    }

    /**
     * Every rule NAME constant must appear in default-thresholds.md.
     */
    #[Test]
    public function itDocumentsAllRuleNamesInDefaultThresholds(): void
    {
        $ruleNames = $this->collectAllRuleNames();
        $thresholdsContent = $this->readFile('website/docs/reference/default-thresholds.md');

        // computed.health is a synthetic rule — not listed in default-thresholds.md
        // architecture.circular-dependency has no numeric thresholds — documented separately
        // architecture.layer-violation has no numeric thresholds either — documented separately
        $exemptions = [
            'computed.health',
            'architecture.circular-dependency',
            'architecture.layer-violation',
        ];

        $missing = [];

        foreach ($ruleNames as $name) {
            if (\in_array($name, $exemptions, true)) {
                continue;
            }

            if (!str_contains($thresholdsContent, $name)) {
                $missing[] = $name;
            }
        }

        self::assertSame(
            [],
            $missing,
            "Rules missing from website/docs/reference/default-thresholds.md:\n" . implode("\n", $missing),
        );
    }

    /**
     * Every CLI alias from rule classes must appear in src/Analysis/Configuration/README.md,
     * and its target option key must be documented as the table's Field cell.
     */
    #[Test]
    public function itDocumentsAllCliAliasesInConfigurationReadme(): void
    {
        $aliases = $this->collectAllCliAliases();
        $configReadme = $this->readFile('src/Analysis/Configuration/README.md');

        $missing = [];
        $missingTargetKeys = [];

        foreach ($aliases as $alias => $info) {
            // Aliases appear as `--alias-name` in the markdown table
            $cliOption = '--' . $alias;
            if (!str_contains($configReadme, $cliOption)) {
                $missing[] = $cliOption . ' (rule: ' . $info['rule'] . ')';
            }

            // The alias target key (e.g. `callable.warning`) must also appear,
            // so a stale Field cell that drifts from the rule's real options fails here.
            if (!str_contains($configReadme, $info['option'])) {
                $missingTargetKeys[] = $cliOption . ' -> ' . $info['option'];
            }
        }

        self::assertSame(
            [],
            $missing,
            "CLI aliases missing from src/Analysis/Configuration/README.md:\n" . implode("\n", $missing),
        );
        self::assertSame(
            [],
            $missingTargetKeys,
            "CLI alias target keys missing from src/Analysis/Configuration/README.md:\n" . implode("\n", $missingTargetKeys),
        );
    }

    /**
     * Every LayerViolationRule CLI alias must appear as its own option in both
     * language variants of the website CLI reference. Matching the complete
     * inline-code token prevents `--layer-violation-severity` from falsely
     * satisfying the primary `--layer-violation` alias.
     */
    #[Test]
    public function itDocumentsLayerViolationCliAliasesInWebsiteCliReferences(): void
    {
        $layerViolationAliases = array_filter(
            $this->collectAllCliAliases(),
            static fn(array $alias): bool => $alias['rule'] === 'architecture.layer-violation',
        );
        self::assertNotEmpty($layerViolationAliases, 'No CLI aliases discovered for architecture.layer-violation.');

        foreach ([
            'website/docs/usage/cli-options.md',
            'website/docs/usage/cli-options.ru.md',
        ] as $path) {
            $content = $this->readFile($path);
            $missing = [];

            foreach (array_keys($layerViolationAliases) as $alias) {
                $pattern = '/`--' . preg_quote($alias, '/') . '(?:=[^`]*)?`/';
                if (preg_match($pattern, $content) !== 1) {
                    $missing[] = '--' . $alias;
                }
            }

            self::assertSame(
                [],
                $missing,
                "LayerViolationRule CLI aliases missing from {$path}:\n" . implode("\n", $missing),
            );
        }
    }

    /**
     * YAML examples in README.md must parse and reference existing rule names.
     */
    #[Test]
    public function itValidatesReadmeYamlExamples(): void
    {
        $readme = $this->readFile('README.md');
        $ruleNames = $this->collectAllRuleNames();

        // Extract YAML code blocks
        preg_match_all('/```yaml\n(.*?)```/s', $readme, $matches);

        self::assertNotEmpty($matches[1], 'No YAML blocks found in README.md');

        foreach ($matches[1] as $yamlBlock) {
            $parsed = Yaml::parse($yamlBlock);
            self::assertIsArray($parsed, "YAML block failed to parse:\n" . $yamlBlock);

            // If the block has a 'rules' section, check rule names
            if (isset($parsed['rules']) && \is_array($parsed['rules'])) {
                foreach (array_keys($parsed['rules']) as $ruleName) {
                    self::assertContains(
                        $ruleName,
                        $ruleNames,
                        "README.md references non-existent rule '{$ruleName}'. "
                        . 'Valid rules: ' . implode(', ', $ruleNames),
                    );
                }
            }
        }
    }

    /**
     * The compact rule catalog inside the llms-only block of rules/index.md
     * must list every actual rule NAME. Drift here makes llms-full.txt lie to
     * agents about which rules exist.
     */
    #[Test]
    public function itListsAllRulesInLlmsOnlyRuleCatalog(): void
    {
        $index = $this->readFile('website/docs/rules/index.md');

        $blockMatched = preg_match(
            '/<!--\s*llms-only\s*\n(.*?)-->/s',
            $index,
            $matches,
        );
        self::assertSame(
            1,
            $blockMatched,
            'website/docs/rules/index.md is missing the <!-- llms-only ... --> compact rule catalog.',
        );

        $body = $matches[1];

        // Slugs appear inside inline-code spans, e.g. `complexity.ccn`.
        preg_match_all('/`([a-z][a-z-]*(?:\.[a-z][a-z-]*)+)`/', $body, $slugMatches);
        $declared = array_values(array_unique($slugMatches[1]));
        sort($declared);

        $actual = $this->collectAllRuleNames();
        // Catalog entries that are not real RuleInterface implementations
        // (tcc/lcc are inputs to other rules; computed.health is synthetic).
        $catalogOnly = ['cohesion.tcc', 'cohesion.lcc'];
        $sourceOnly = ['computed.health'];

        $expected = array_values(array_diff($actual, $sourceOnly));
        $declaredWithoutCatalogOnly = array_values(array_diff($declared, $catalogOnly));
        sort($expected);
        sort($declaredWithoutCatalogOnly);

        self::assertSame(
            $expected,
            $declaredWithoutCatalogOnly,
            'Compact rule catalog in website/docs/rules/index.md drifted from capability-owned rule roots. '
            . 'Update the <!-- llms-only ... --> block to match.',
        );
    }

    /**
     * Health-score documentation must only demonstrate format names that the
     * running formatter registry exposes. This keeps both language variants
     * coupled to the actual CLI output surface rather than to a stale list.
     */
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

    /**
     * Baseline-count publications are derived from the active baseline rather
     * than maintained as independent documentation constants. The inventory is
     * deliberately closed: an added, removed, duplicated, or malformed current
     * publication fails this test.
     */
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
     * Collects all rule NAME constants from every current rule capability root.
     *
     * @return list<string>
     */
    private function collectAllRuleNames(): array
    {
        $names = [];

        foreach ($this->scanRuleClasses() as ['fqcn' => $fqcn, 'reflection' => $reflection]) {
            if ($reflection->hasConstant('NAME')) {
                $name = $reflection->getConstant('NAME');
                if (\is_string($name)) {
                    $names[] = $name;
                }
            }
        }

        sort($names);

        return $names;
    }

    /**
     * Collects all CLI aliases from rule classes.
     *
     * @return array<string, array{rule: string, option: string}>
     */
    private function collectAllCliAliases(): array
    {
        $aliases = [];

        foreach ($this->scanRuleClasses() as ['fqcn' => $fqcn, 'reflection' => $reflection]) {
            $ruleName = $reflection->hasConstant('NAME')
                ? $reflection->getConstant('NAME')
                : null;

            if (!\is_string($ruleName)) {
                continue;
            }

            $ruleAliases = CliAliasReader::read($fqcn);

            foreach ($ruleAliases as $alias => $option) {
                $aliases[$alias] = [
                    'rule' => $ruleName,
                    'option' => $option,
                ];
            }
        }

        return $aliases;
    }

    /**
     * Scans every current rule root for concrete RuleInterface
     * implementations.
     *
     * Every current capability root is explicit so a future capability does not
     * silently enroll itself in documentation discovery.
     *
     * @return iterable<array{fqcn: class-string<RuleDefinitionInterface>, reflection: ReflectionClass<RuleInterface>}>
     */
    private function scanRuleClasses(): iterable
    {
        $rulesDirs = [
            self::$projectRoot . '/src/Analysis/Policy/Architecture/LayerViolation',
            self::$projectRoot . '/src/Analysis/Policy/Inline/Directive',
            self::$projectRoot . '/src/Analysis/Evidence/CircularDependency',
            self::$projectRoot . '/src/Analysis/Evidence/Duplication',
            self::$projectRoot . '/src/Analysis/Evidence/CodeSmell',
            self::$projectRoot . '/src/Analysis/Evidence/Cohesion',
            self::$projectRoot . '/src/Analysis/Evidence/Complexity',
            self::$projectRoot . '/src/Analysis/Evidence/Coupling',
            self::$projectRoot . '/src/Analysis/Evidence/Design',
            self::$projectRoot . '/src/Analysis/Evidence/Maintainability',
            self::$projectRoot . '/src/Analysis/Evidence/Security',
            self::$projectRoot . '/src/Analysis/Evidence/Size',
            self::$projectRoot . '/src/Analysis/Run/ExcludeBinding',
            self::$projectRoot . '/src/Analysis/Finding/SuppressionBinding',
        ];

        foreach ($rulesDirs as $rulesDir) {
            if (!is_dir($rulesDir)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($rulesDir, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (!str_ends_with($file->getFilename(), 'Rule.php') || str_starts_with($file->getFilename(), 'Abstract')) {
                    continue;
                }

                $content = file_get_contents($file->getPathname());
                \assert($content !== false);

                if (preg_match('/^namespace\s+([\w\\\\]+);/m', $content, $nsMatch) === 1
                    && preg_match('/^(?:final\s+)?class\s+(\w+)/m', $content, $classMatch) === 1) {
                    $fqcn = $nsMatch[1] . '\\' . $classMatch[1];

                    if (!class_exists($fqcn)) {
                        continue;
                    }

                    $reflection = new ReflectionClass($fqcn);

                    if ($reflection->isAbstract() || !$reflection->implementsInterface(RuleInterface::class)) {
                        continue;
                    }

                    yield ['fqcn' => $fqcn, 'reflection' => $reflection]; // @phpstan-ignore generator.valueType
                }
            }
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

    private static function commentContent(string $extension, string $content): string
    {
        if ($extension === 'php') {
            $comments = [];

            foreach (token_get_all($content) as $token) {
                if (\is_array($token) && \in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                    $comments[] = $token[1];
                }
            }

            return implode("\n", $comments);
        }

        return match ($extension) {
            'py' => self::hashCommentsOutsideStrings($content),
            'js', 'mjs' => self::javascriptCommentsOutsideStrings($content),
            default => '',
        };
    }

    private static function hashCommentsOutsideStrings(string $content): string
    {
        $comments = [];
        $length = \strlen($content);
        $quote = null;
        $triple = false;

        for ($offset = 0; $offset < $length; ++$offset) {
            $character = $content[$offset];

            if ($quote !== null) {
                if ($character === '\\') {
                    ++$offset;
                    continue;
                }

                if ($triple && substr($content, $offset, 3) === str_repeat($quote, 3)) {
                    $offset += 2;
                    $quote = null;
                    $triple = false;
                } elseif (!$triple && $character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if (($character === "'" || $character === '"')) {
                $quote = $character;
                $triple = substr($content, $offset, 3) === str_repeat($character, 3);
                if ($triple) {
                    $offset += 2;
                }
                continue;
            }

            if ($character === '#') {
                $end = strpos($content, "\n", $offset);
                $end = $end === false ? $length : $end;
                $comments[] = substr($content, $offset, $end - $offset);
                $offset = $end;
            }
        }

        return implode("\n", $comments);
    }

    private static function javascriptCommentsOutsideStrings(string $content): string
    {
        $comments = [];
        $length = \strlen($content);
        $quote = null;

        for ($offset = 0; $offset < $length; ++$offset) {
            $character = $content[$offset];

            if ($quote !== null) {
                if ($character === '\\') {
                    ++$offset;
                    continue;
                }

                if ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === "'" || $character === '"' || $character === '`') {
                $quote = $character;
                continue;
            }

            $pair = substr($content, $offset, 2);
            if ($pair === '//') {
                $end = strpos($content, "\n", $offset);
                $end = $end === false ? $length : $end;
                $comments[] = substr($content, $offset, $end - $offset);
                $offset = $end;
            } elseif ($pair === '/*') {
                $end = strpos($content, '*/', $offset + 2);
                $end = $end === false ? $length - 2 : $end;
                $comments[] = substr($content, $offset, $end + 2 - $offset);
                $offset = $end + 1;
            }
        }

        return implode("\n", $comments);
    }
}
