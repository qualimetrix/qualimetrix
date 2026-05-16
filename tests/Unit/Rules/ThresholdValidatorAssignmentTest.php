<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Rules;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Rule\Override\OverrideValidatorInterface;
use Qualimetrix\Core\Rule\Override\StandardOverrideValidator;
use Qualimetrix\Core\Rule\ThresholdAwareOptionsInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Structural invariants for per-rule threshold override validators.
 *
 * Two anti-regression checks:
 *
 * 1. Every ThresholdAwareOptions class returns a valid OverrideValidatorInterface.
 * 2. If an Options class's `fromArray([])` defaults have warning > error
 *    on any threshold pair, the validator MUST be non-Standard. This
 *    catches the bug class that shipped with v0.18.0 (latent in
 *    Maintainability since earlier releases), where inverted-threshold
 *    rules used the Standard validator and the parser rejected user
 *    overrides that matched the rule's natural orientation.
 */
final class ThresholdValidatorAssignmentTest extends TestCase
{
    #[Test]
    public function everyThresholdAwareOptionsReturnsAValidator(): void
    {
        $checked = 0;

        foreach ($this->discoverThresholdAwareOptions() as $reflection) {
            $validator = $reflection->getName()::getOverrideValidator();

            self::assertInstanceOf(
                OverrideValidatorInterface::class,
                $validator,
                "{$reflection->getShortName()}::getOverrideValidator() must return OverrideValidatorInterface",
            );
            ++$checked;
        }

        self::assertGreaterThanOrEqual(27, $checked, 'Expected at least 27 ThresholdAware Options classes');
    }

    #[Test]
    public function invertedDefaultsRequireNonStandardValidator(): void
    {
        $standard = StandardOverrideValidator::instance();

        foreach ($this->discoverThresholdAwareOptions() as $reflection) {
            $hasInvertedPair = $this->hasInvertedThresholdDefaults($reflection);
            if (!$hasInvertedPair) {
                continue;
            }

            $validator = $reflection->getName()::getOverrideValidator();

            self::assertNotSame(
                $standard,
                $validator,
                \sprintf(
                    "%s::fromArray([]) has at least one warning > error default pair, so its validator must NOT be StandardOverrideValidator. " .
                    "Standard treats warning > error as a user error, but for this rule that is the natural orientation. " .
                    "Return InvertedOverrideValidator, IndependentAxisValidator, or WarningOnlyValidator instead.",
                    $reflection->getShortName(),
                ),
            );
        }
    }

    /**
     * @return iterable<ReflectionClass<ThresholdAwareOptionsInterface>>
     */
    private function discoverThresholdAwareOptions(): iterable
    {
        $optionsDir = \dirname(__DIR__, 3) . '/src/Rules';
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($optionsDir, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (!str_ends_with($file->getFilename(), 'Options.php')) {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            \assert($content !== false);

            if (preg_match('/^namespace\s+([\w\\\\]+);/m', $content, $nsMatch) !== 1
                || preg_match('/^final\s+(?:readonly\s+)?class\s+(\w+)/m', $content, $classMatch) !== 1) {
                continue;
            }

            $fqcn = $nsMatch[1] . '\\' . $classMatch[1];
            if (!class_exists($fqcn)) {
                continue;
            }

            $reflection = new ReflectionClass($fqcn);
            if ($reflection->isAbstract() || !$reflection->implementsInterface(ThresholdAwareOptionsInterface::class)) {
                continue;
            }

            yield $reflection; // @phpstan-ignore generator.valueType
        }
    }

    /**
     * Detects whether `fromArray([])` returns an instance whose constructor
     * defaults pair any `*Warning` property with a `*Error` counterpart
     * where Warning > Error. Heuristic — matches the natural pairing
     * convention used across the Options layer (`warning`/`error`,
     * `paramWarning`/`paramError`, etc.).
     *
     * @param ReflectionClass<ThresholdAwareOptionsInterface> $reflection
     */
    private function hasInvertedThresholdDefaults(ReflectionClass $reflection): bool
    {
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return false;
        }

        /** @var array<string, int|float> $numericDefaults */
        $numericDefaults = [];

        foreach ($constructor->getParameters() as $param) {
            if (!$param->isDefaultValueAvailable()) {
                continue;
            }

            $type = $param->getType();
            $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;
            if ($typeName !== 'int' && $typeName !== 'float') {
                continue;
            }

            $default = $param->getDefaultValue();
            \assert(\is_int($default) || \is_float($default));
            $numericDefaults[$param->getName()] = $default;
        }

        foreach ($numericDefaults as $name => $warningValue) {
            if (!str_ends_with($name, 'Warning') && $name !== 'warning') {
                continue;
            }

            $errorName = $name === 'warning' ? 'error' : substr($name, 0, -7) . 'Error';
            if (!isset($numericDefaults[$errorName])) {
                continue;
            }

            $errorValue = $numericDefaults[$errorName];
            if ($warningValue > $errorValue) {
                return true;
            }
        }

        return false;
    }
}
