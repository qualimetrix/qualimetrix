<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\ThresholdKeys;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdAwareOptionsInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use RuntimeException;

/**
 * Reflection-based safety net: for every Options class implementing
 * {@see ThresholdAwareOptionsInterface}, verifies that `withOverride()`
 * preserves all non-threshold constructor properties.
 *
 * This catches the "forgotten field" bug automatically when new properties are
 * added to any Options class without updating its `withOverride()` method —
 * a property of the whole `ThresholdAwareOptionsInterface` population, not of
 * any one rule, which is why it lives apart from the per-class cases in
 * {@see \Qualimetrix\Tests\Analysis\Policy\Inline\Unit\ThresholdOverrideIntegrationTest}.
 */
final class ThresholdAwareOptionsFieldPreservationTest extends TestCase
{
    /**
     * Reflection-based safety net: for every Options class implementing ThresholdAwareOptionsInterface,
     * verifies that withOverride() preserves all non-threshold constructor properties.
     *
     * This catches the "forgotten field" bug automatically when new properties are added
     * to any Options class without updating its withOverride() method.
     */
    #[Test]
    public function itPreservesAllFieldsViaReflectionForAllThresholdAwareOptions(): void
    {
        $optionsDirs = [
            \dirname(__DIR__, 2) . '/src/Analysis/Evidence/Duplication',
            \dirname(__DIR__, 2) . '/src/Analysis/Evidence/CodeSmell',
            \dirname(__DIR__, 2) . '/src/Analysis/Evidence/Cohesion',
            \dirname(__DIR__, 2) . '/src/Analysis/Evidence/Complexity',
            \dirname(__DIR__, 2) . '/src/Analysis/Evidence/Coupling',
            \dirname(__DIR__, 2) . '/src/Analysis/Evidence/Design',
            \dirname(__DIR__, 2) . '/src/Analysis/Evidence/Maintainability',
            \dirname(__DIR__, 2) . '/src/Analysis/Evidence/Security',
            \dirname(__DIR__, 2) . '/src/Analysis/Evidence/Size',
        ];

        $testedClasses = 0;

        foreach ($optionsDirs as $optionsDir) {
            $optionsFiles = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($optionsDir, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($optionsFiles as $file) {
                if (!str_ends_with($file->getFilename(), 'Options.php')) {
                    continue;
                }

                $content = file_get_contents($file->getPathname());
                \assert($content !== false);

                if (preg_match('/^namespace\s+([\w\\\\]+);/m', $content, $nsMatch) === 1
                    && preg_match('/^final\s+readonly\s+class\s+(\w+)/m', $content, $classMatch) === 1) {
                    $fqcn = $nsMatch[1] . '\\' . $classMatch[1];

                    if (!class_exists($fqcn)) {
                        continue;
                    }

                    $reflection = new ReflectionClass($fqcn);

                    if ($reflection->isAbstract() || !$reflection->implementsInterface(ThresholdAwareOptionsInterface::class)) {
                        continue;
                    }

                    $this->assertWithOverridePreservesAllProperties($reflection); // @phpstan-ignore argument.type
                    ++$testedClasses;
                }
            }
        }

        // Sanity: we should have tested all 27 classes
        self::assertGreaterThanOrEqual(27, $testedClasses, 'Expected at least 27 ThresholdAwareOptions classes');
    }

    /**
     * @param ReflectionClass<ThresholdAwareOptionsInterface> $reflection
     */
    private function assertWithOverridePreservesAllProperties(ReflectionClass $reflection): void
    {
        $className = $reflection->getShortName();
        $constructor = $reflection->getConstructor();
        self::assertNotNull($constructor, "{$className}: must have a constructor");

        // Build an instance with non-default values for every property
        $args = [];

        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();
            $typeName = $type instanceof ReflectionNamedType ? $type->getName() : 'mixed';
            $args[$param->getName()] = $this->generateNonDefaultValue($typeName, $param);
        }

        $instance = $reflection->newInstanceArgs($args);
        \assert($instance instanceof ThresholdAwareOptionsInterface);

        // Part 1: withOverride(null, null) must preserve all properties
        $overridden = $instance->withOverride(null, null);

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            self::assertSame(
                $prop->getValue($instance),
                $prop->getValue($overridden),
                "{$className}::\${$prop->getName()} must be preserved by withOverride(null, null)",
            );
        }

        // Part 2: withOverride(value, value) must actually change threshold properties
        $overridden = $instance->withOverride(111, 222);
        $changedCount = 0;

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->getValue($instance) !== $prop->getValue($overridden)) {
                ++$changedCount;
            }
        }

        self::assertGreaterThanOrEqual(
            1,
            $changedCount,
            "{$className}: withOverride(111, 222) must change at least one property",
        );
    }

    /**
     * Generates a non-default value for a constructor parameter to detect if withOverride() loses it.
     */
    private function generateNonDefaultValue(string $typeName, ReflectionParameter $param): mixed
    {
        // Use a value different from the default
        $default = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;

        return match ($typeName) {
            'bool' => $default !== false ? false : true,
            'int' => $default !== 99 ? 99 : 98,
            'float' => $default !== 99.9 ? 99.9 : 98.8,
            'string' => $default !== 'test_override' ? 'test_override' : 'test_other',
            'array' => $default !== ['test_ns'] ? ['test_ns'] : ['other_ns'],
            default => $param->allowsNull() ? null : throw new RuntimeException(
                "Cannot generate test value for {$param->getName()} of type {$typeName}",
            ),
        };
    }
}
