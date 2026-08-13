<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Integration\Documentation;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Rule\CliAliasReader;
use Qualimetrix\Core\Rule\RuleInterface;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Reporting\Formatter\FormatterRegistryInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
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
    private static string $projectRoot;

    public static function setUpBeforeClass(): void
    {
        self::$projectRoot = \dirname(__DIR__, 3);
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
     * Every CLI alias from rule classes must appear in src/Analysis/Configuration/README.md.
     */
    #[Test]
    public function itDocumentsAllCliAliasesInConfigurationReadme(): void
    {
        $aliases = $this->collectAllCliAliases();
        $configReadme = $this->readFile('src/Analysis/Configuration/README.md');

        $missing = [];

        foreach (array_keys($aliases) as $alias) {
            // Aliases appear as `--alias-name` in the markdown table
            $cliOption = '--' . $alias;
            if (!str_contains($configReadme, $cliOption)) {
                $missing[] = $cliOption . ' (rule: ' . $aliases[$alias]['rule'] . ')';
            }
        }

        self::assertSame(
            [],
            $missing,
            "CLI aliases missing from src/Analysis/Configuration/README.md:\n" . implode("\n", $missing),
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

        // Slugs appear inside inline-code spans, e.g. `complexity.cyclomatic`.
        preg_match_all('/`([a-z][a-z-]*\.[a-z][a-z-]+)`/', $body, $slugMatches);
        $declared = array_values(array_unique($slugMatches[1]));
        sort($declared);

        $actual = $this->collectAllRuleNames();
        // Catalog entries that are not real RuleInterface implementations
        // (tcc/lcc are inputs to other rules; computed.health is synthetic).
        $catalogOnly = ['tcc', 'lcc'];
        $sourceOnly = ['computed.health'];

        $expected = array_values(array_diff($actual, $sourceOnly));
        $declaredWithoutCatalogOnly = array_values(array_diff($declared, $catalogOnly));
        sort($expected);
        sort($declaredWithoutCatalogOnly);

        self::assertSame(
            $expected,
            $declaredWithoutCatalogOnly,
            'Compact rule catalog in website/docs/rules/index.md drifted from src/Rules/. '
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
     * Architecture and Duplication own rules outside the remaining layered
     * src/Rules tree; every root is explicit so a future capability does not
     * silently enroll itself in documentation discovery.
     *
     * @return iterable<array{fqcn: class-string<RuleInterface>, reflection: ReflectionClass<RuleInterface>}>
     */
    private function scanRuleClasses(): iterable
    {
        $rulesDirs = [
            self::$projectRoot . '/src/Rules',
            self::$projectRoot . '/src/Architecture/Rules',
            self::$projectRoot . '/src/Analysis/Evidence/Duplication',
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

    private function readFile(string $relativePath): string
    {
        $path = self::$projectRoot . '/' . $relativePath;
        self::assertFileExists($path, "Documentation file not found: {$relativePath}");

        $content = file_get_contents($path);
        \assert($content !== false);

        return $content;
    }
}
