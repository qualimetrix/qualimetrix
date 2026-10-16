<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Support;

use LogicException;
use Qualimetrix\Infrastructure\Console\Check\CheckCommand;
use ReflectionClass;

final class CheckCommandFixture
{
    /** @param array<string, mixed> $replacements */
    public static function replace(CheckCommand $original, array $replacements): CheckCommand
    {
        $reflection = new ReflectionClass(CheckCommand::class);
        $constructor = $reflection->getConstructor() ?? throw new LogicException('Check command has no constructor.');
        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();
            $arguments[] = \array_key_exists($name, $replacements) ? $replacements[$name] : $reflection->getProperty($name)->getValue($original);
        }
        return $reflection->newInstanceArgs($arguments);
    }
}
