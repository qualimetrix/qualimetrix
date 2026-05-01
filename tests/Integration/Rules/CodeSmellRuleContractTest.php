<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Integration\Rules;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Metrics\CodeSmell\CodeSmellCollector;
use Qualimetrix\Rules\CodeSmell\AbstractCodeSmellRule;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * Guards the implicit contract between AbstractCodeSmellRule and its concrete subclasses.
 *
 * AbstractCodeSmellRule reads metadata from typed class constants (NAME, SMELL_TYPE,
 * MESSAGE_TEMPLATE, ...) with permissive empty-string defaults. A subclass that forgets
 * to override one of them would silently produce zero violations at runtime — this test
 * fails fast when that happens.
 */
final class CodeSmellRuleContractTest extends TestCase
{
    #[Test]
    public function everySubclassDeclaresMandatoryConstants(): void
    {
        $issues = [];

        foreach ($this->scanCodeSmellRules() as $reflection) {
            $name = $reflection->getConstant('NAME');
            if (!\is_string($name) || $name === '') {
                $issues[] = "{$reflection->getName()}::NAME must be a non-empty string";
            } elseif (!str_starts_with($name, 'code-smell.')) {
                $issues[] = "{$reflection->getName()}::NAME must start with 'code-smell.', got '{$name}'";
            }

            $smellType = $reflection->getConstant('SMELL_TYPE');
            if (!\is_string($smellType) || $smellType === '') {
                $issues[] = "{$reflection->getName()}::SMELL_TYPE must be a non-empty string";
            } elseif (!\in_array($smellType, CodeSmellCollector::SMELL_TYPES, true)) {
                $issues[] = "{$reflection->getName()}::SMELL_TYPE '{$smellType}' is not produced by CodeSmellCollector";
            }

            $template = $reflection->getConstant('MESSAGE_TEMPLATE');
            if (!\is_string($template) || $template === '') {
                $issues[] = "{$reflection->getName()}::MESSAGE_TEMPLATE must be a non-empty string";
            }
        }

        self::assertSame([], $issues, "Code smell rule contract violations:\n" . implode("\n", $issues));
    }

    /**
     * @return iterable<ReflectionClass<AbstractCodeSmellRule>>
     */
    private function scanCodeSmellRules(): iterable
    {
        $dir = \dirname(__DIR__, 3) . '/src/Rules/CodeSmell';
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            $name = $file->getFilename();
            if (!str_ends_with($name, 'Rule.php') || str_starts_with($name, 'Abstract')) {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            \assert($content !== false);

            if (preg_match('/^namespace\s+([\w\\\\]+);/m', $content, $nsMatch) !== 1
                || preg_match('/^(?:final\s+)?class\s+(\w+)/m', $content, $classMatch) !== 1) {
                continue;
            }

            /** @var class-string $fqcn */
            $fqcn = $nsMatch[1] . '\\' . $classMatch[1];
            if (!class_exists($fqcn)) {
                continue;
            }

            $reflection = new ReflectionClass($fqcn);
            if ($reflection->isAbstract() || !$reflection->isSubclassOf(AbstractCodeSmellRule::class)) {
                continue;
            }

            yield $reflection;
        }
    }
}
