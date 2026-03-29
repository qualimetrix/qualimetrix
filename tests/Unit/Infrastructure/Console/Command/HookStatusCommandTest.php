<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Command\HookStatusCommand;
use Qualimetrix\Infrastructure\Git\GitRepositoryLocator;

/**
 * Smoke tests for HookStatusCommand.
 *
 * GitRepositoryLocator is a final class without an interface, so execute()
 * scenarios requiring a mock (e.g., "not a git repo") cannot be tested
 * at the unit level. Only configuration tests are included here.
 */
#[CoversClass(HookStatusCommand::class)]
final class HookStatusCommandTest extends TestCase
{
    #[Test]
    public function configuresSetsNameAndDescription(): void
    {
        $command = new HookStatusCommand(new GitRepositoryLocator());

        self::assertSame('hook:status', $command->getName());
        self::assertSame('Show status of git pre-commit hook', $command->getDescription());
    }

    #[Test]
    public function hasNoOptions(): void
    {
        $command = new HookStatusCommand(new GitRepositoryLocator());
        $definition = $command->getDefinition();

        // HookStatusCommand defines no custom options (only inherited --help, etc.)
        self::assertSame([], $definition->getOptions());
    }

    #[Test]
    public function hasNoArguments(): void
    {
        $command = new HookStatusCommand(new GitRepositoryLocator());
        $definition = $command->getDefinition();

        self::assertSame([], $definition->getArguments());
    }
}
