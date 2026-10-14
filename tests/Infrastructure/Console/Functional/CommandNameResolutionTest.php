<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Subprocess\ChildProcess;

require_once \dirname(__DIR__, 4) . '/scripts/subprocess/ChildProcess.php';

final class CommandNameResolutionTest extends TestCase
{
    #[Test]
    public function itRefusesInexactTopLevelNamesWithoutInteractiveConfirmation(): void
    {
        foreach (['chek', 'che', 'CHECK', 'baseline'] as $name) {
            $result = self::runBin([$name, '--format=json'], "yes\n");
            self::assertSame(3, $result['exitCode'], $result['stderr']);
            $document = json_decode($result['stdout'], true, flags: \JSON_THROW_ON_ERROR);
            self::assertSame(3, $document['exit_code']);
            self::assertStringContainsString($name === 'baseline' ? 'baseline:' : 'check', $document['error']);
            self::assertSame('', $result['stderr']);
        }
        $result = self::runBin(['che', '-h']);
        self::assertSame(3, $result['exitCode']);
        self::assertStringContainsString('check', $result['stderr']);
        $result = self::runBin(['check', 'src', '-f', 'json', '--bogus']);
        self::assertSame(3, $result['exitCode']);
        self::assertSame(3, json_decode($result['stdout'], true, flags: \JSON_THROW_ON_ERROR)['exit_code']);
    }

    #[Test]
    public function itPreservesVendorHelpListsAndPrefixCompletion(): void
    {
        foreach ([['help', 'che'], ['list', 'baseline'], [], ['check', '-h']] as $arguments) {
            $result = self::runBin($arguments);
            self::assertSame(0, $result['exitCode'], $result['stderr']);
            self::assertNotSame('', $result['stdout']);
        }
        self::assertSame(3, self::runBin(['help', 'chek'])['exitCode']);
        foreach (['che' => 'check', 'b' => 'baseline:generate', 'baseline:g' => 'baseline:generate'] as $prefix => $expected) {
            $result = self::runBin(['_complete', '--shell=bash', '--current=1', '--input=qmx', '--input=' . $prefix]);
            self::assertSame(0, $result['exitCode'], $result['stderr']);
            self::assertStringContainsString($expected, $result['stdout']);
        }
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    private static function runBin(array $arguments, string $stdin = ''): array
    {
        return ChildProcess::run([\PHP_BINARY, \dirname(__DIR__, 4) . '/bin/qmx', ...$arguments], stdin: $stdin);
    }
}
