<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(CheckCommand::class)]
final class RetiredOutputFormatTest extends TestCase
{
    #[Test]
    #[TestWith([false])]
    #[TestWith([true])]
    public function itRefusesTheRetiredFormatFromEitherInputDoor(bool $fromConfiguration): void
    {
        $fixture = sys_get_temp_dir() . '/qmx-retired-format-' . bin2hex(random_bytes(6));
        mkdir($fixture);
        file_put_contents($fixture . '/qmx.yaml', $fromConfiguration ? "format: text-verbose\n" : "{}\n");
        file_put_contents($fixture . '/Source.php', "<?php\nclass Source {}\n");
        $previous = (string) getcwd();
        chdir($fixture);

        try {
            $command = (new ContainerFactory())->create()->get(CheckCommand::class);
            self::assertInstanceOf(CheckCommand::class, $command);
            $tester = new CommandTester($command);
            $options = ['paths' => ['Source.php'], '--workers' => '0', '--no-cache' => true, '--config' => 'qmx.yaml', '--fail-on' => 'none'];
            if (!$fromConfiguration) {
                $options['--format'] = 'text-verbose';
            }
            $tester->execute($options, ['capture_stderr_separately' => true]);

            self::assertSame(3, $tester->getStatusCode(), $tester->getErrorOutput());
            self::assertSame('', $tester->getDisplay());
            self::assertStringContainsString('Output format "text-verbose" is not one of:', $tester->getErrorOutput());
            self::assertStringContainsString('summary', $tester->getErrorOutput());
            self::assertStringContainsString('text', $tester->getErrorOutput());
        } finally {
            chdir($previous);
            unlink($fixture . '/Source.php');
            unlink($fixture . '/qmx.yaml');
            rmdir($fixture);
        }
    }
}
