<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Command\HookStatusCommand;
use Qualimetrix\Infrastructure\Git\GitRepositoryLocator;

/**
 * How the command is addressed and what it accepts. What it does when it runs
 * is the functional file of the same name, under Functional/Command/.
 *
 * The name is a promise: it is what a user types and what the hook
 * documentation tells them to type. The description is not, so it is not
 * pinned here.
 */
#[CoversClass(HookStatusCommand::class)]
final class HookStatusCommandTest extends TestCase
{
    #[Test]
    public function itIsAddressedAsHookStatus(): void
    {
        $command = new HookStatusCommand(new GitRepositoryLocator());

        self::assertSame('hook:status', $command->getName());
    }

    #[Test]
    public function itDefinesNoCustomOptions(): void
    {
        $command = new HookStatusCommand(new GitRepositoryLocator());
        $definition = $command->getDefinition();

        // HookStatusCommand defines no custom options (only inherited --help, etc.)
        self::assertSame([], $definition->getOptions());
    }

    #[Test]
    public function itDefinesNoArguments(): void
    {
        $command = new HookStatusCommand(new GitRepositoryLocator());
        $definition = $command->getDefinition();

        self::assertSame([], $definition->getArguments());
    }
}
