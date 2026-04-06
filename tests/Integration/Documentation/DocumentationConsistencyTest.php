<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Integration\Documentation;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Rule\RuleInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Symfony\Component\Yaml\Yaml;

/**
 * Validates that documentation stays in sync with source code.
 *
 * These tests catch stale documentation automatically in CI:
 * - Rule names missing from default-thresholds.md
 * - CLI aliases missing from Configuration/README.md
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
    public function testAllRuleNamesDocumentedInDefaultThresholds(): void
    {
        $ruleNames = $this->collectAllRuleNames();
        $thresholdsContent = $this->readFile('website/docs/reference/default-thresholds.md');

        // computed.health is a synthetic rule — not listed in default-thresholds.md
        // architecture.circular-dependency has no numeric thresholds — documented separately
        $exemptions = [
            'computed.health',
            'architecture.circular-dependency',
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
     * Every CLI alias from rule classes must appear in src/Configuration/README.md.
     */
    public function testAllCliAliasesDocumentedInConfigurationReadme(): void
    {
        $aliases = $this->collectAllCliAliases();
        $configReadme = $this->readFile('src/Configuration/README.md');

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
            "CLI aliases missing from src/Configuration/README.md:\n" . implode("\n", $missing),
        );
    }

    /**
     * YAML examples in README.md must parse and reference existing rule names.
     */
    public function testReadmeYamlExamplesAreValid(): void
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
     * Collects all rule NAME constants by scanning src/Rules/ directory.
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

            /** @var array<string, string> $ruleAliases */
            $ruleAliases = $fqcn::getCliAliases();

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
     * Scans src/Rules/ for concrete RuleInterface implementations.
     *
     * @return iterable<array{fqcn: class-string<RuleInterface>, reflection: ReflectionClass<RuleInterface>}>
     */
    private function scanRuleClasses(): iterable
    {
        $rulesDir = self::$projectRoot . '/src/Rules';
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

    private function readFile(string $relativePath): string
    {
        $path = self::$projectRoot . '/' . $relativePath;
        self::assertFileExists($path, "Documentation file not found: {$relativePath}");

        $content = file_get_contents($path);
        \assert($content !== false);

        return $content;
    }
}
