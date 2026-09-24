<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Symfony\Component\Console\Input\InputInterface;

/**
 * An option or argument value as a command line would have written it.
 *
 * Argv delivers only strings, one per value, and every door reading a valued
 * option or argument is written for that. An embedder's array input delivers
 * the PHP value it was given instead: an integer there is the number a command
 * line would have typed, and any other shape is refused as input (exit 3)
 * rather than left to a type error that reports the product as broken or to
 * a type check that drops the value and runs without it.
 *
 * Flags (`VALUE_NONE`) are read by their callers as booleans and never pass
 * through here; a value-optional option's "written alone" forms are decided by
 * its caller before the value is spelled.
 */
final class CommandLineSpelling
{
    /** A single-valued option; null when the command does not define it or it was not written. */
    public static function option(InputInterface $input, string $name): ?string
    {
        $value = $input->hasOption($name) ? $input->getOption($name) : null;

        return $value === null ? null : self::of($value, '--' . $name);
    }

    /**
     * A repeatable option's values in the order written.
     *
     * @return list<string>
     */
    public static function options(InputInterface $input, string $name): array
    {
        return self::each($input->hasOption($name) ? $input->getOption($name) : null, '--' . $name);
    }

    /** A single-valued argument; null when the command does not define it or it was not given. */
    public static function argument(InputInterface $input, string $name): ?string
    {
        $value = $input->hasArgument($name) ? $input->getArgument($name) : null;

        return $value === null ? null : self::of($value, $name);
    }

    /** A required argument, which the input definition guarantees is present. */
    public static function requiredArgument(InputInterface $input, string $name): string
    {
        return self::of($input->getArgument($name), $name);
    }

    /**
     * A variadic argument's values in the order given.
     *
     * @return list<string>
     */
    public static function arguments(InputInterface $input, string $name): array
    {
        return self::each($input->hasArgument($name) ? $input->getArgument($name) : null, $name);
    }

    /** One value, for a caller that has already decided what its absent and value-less forms mean. */
    public static function of(mixed $value, string $door): string
    {
        return match (true) {
            \is_string($value) => $value,
            \is_int($value) => (string) $value,
            default => throw ConfigurationRefusal::aboutCommandLineInput(
                $door,
                \sprintf('Invalid %s value of type %s: expected it as written on a command line.', $door, get_debug_type($value)),
            ),
        };
    }

    /**
     * A repeatable value: a lone scalar is one value written through an array
     * input, not a list to iterate.
     *
     * @return list<string>
     */
    public static function each(mixed $values, string $door): array
    {
        if ($values === null) {
            return [];
        }

        if (!\is_array($values)) {
            return [self::of($values, $door)];
        }

        return array_map(static fn(mixed $value): string => self::of($value, $door), array_values($values));
    }
}
