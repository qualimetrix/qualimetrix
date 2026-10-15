<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\Process;

final class ProductProbeTypesTest extends TestCase
{
    private const string CURRENT = 'Qualimetrix\\Analysis\\Finding\\Contract\\Channel\\ChannelDeclarationRegistryInterface';
    private const string PREVIOUS = 'Qualimetrix\\Analysis\\Finding\\Contract\\ChannelDeclarationRegistryInterface';

    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    /** @return iterable<string, array{string, string}> */
    public static function existingTypes(): iterable
    {
        $current = 'namespace Qualimetrix\\Analysis\\Finding\\Contract\\Channel; interface ChannelDeclarationRegistryInterface {}';
        $previous = 'namespace Qualimetrix\\Analysis\\Finding\\Contract; interface ChannelDeclarationRegistryInterface {}';
        yield 'current only' => [$current, self::CURRENT];
        yield 'previous only' => [$previous, self::PREVIOUS];
        yield 'both prefer current' => [$current . $previous, self::CURRENT];
    }

    #[Test]
    #[DataProvider('existingTypes')]
    public function itSelectsTheNativeExistingType(string $declarations, string $expected): void
    {
        $result = $this->probe($declarations, 'echo QmxFindingGate\\ProductProbeTypes::existing(' . var_export(self::CURRENT, true) . ');');
        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertSame('', $result['stderr']);
        self::assertSame($expected, $result['stdout']);
    }

    #[Test]
    public function itRefusesWhenNeitherSupportedTypeExists(): void
    {
        $result = $this->probe('', 'try { QmxFindingGate\\ProductProbeTypes::existing(' . var_export(self::CURRENT, true) . '); } catch (RuntimeException $failure) { echo $failure->getMessage(); exit(7); }');
        self::assertSame(7, $result['exit']);
        self::assertSame('', $result['stderr']);
        self::assertSame('The product probe cannot find either supported type for ' . self::CURRENT . '.', $result['stdout']);
    }

    #[Test]
    public function itComparesTheOptionalInterfaceNameWithoutLoadingIt(): void
    {
        $current = 'Qualimetrix\\Analysis\\Finding\\Contract\\Rule\\OptionLanguage\\RuleOptionDocumentFormsInterface';
        $previous = 'Qualimetrix\\Analysis\\Finding\\Contract\\Rule\\RuleOptionDocumentFormsInterface';
        $result = $this->probe('', 'spl_autoload_register(static function(string $name): void { throw new RuntimeException("Unexpected autoload: " . $name); });'
            . ' echo json_encode(['
            . 'QmxFindingGate\\ProductProbeTypes::isName(' . var_export($current, true) . ',' . var_export($current, true) . '),'
            . 'QmxFindingGate\\ProductProbeTypes::isName(' . var_export($previous, true) . ',' . var_export($current, true) . '),'
            . 'QmxFindingGate\\ProductProbeTypes::isName("Other",' . var_export($current, true) . ')]);');
        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertSame('', $result['stderr']);
        self::assertSame('[true,true,false]', $result['stdout']);
    }

    /** @return array{stdout: string, stderr: string, exit: int} */
    private function probe(string $declarations, string $operation): array
    {
        $source = 'require ' . var_export(\dirname(__DIR__) . '/ProductProbeTypes.php', true) . ';'
            . 'eval(' . var_export($declarations, true) . ');' . $operation;

        return Process::run([\PHP_BINARY, '-r', $source], \dirname(__DIR__, 3));
    }
}
