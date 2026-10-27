<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\CodeSmell\Integration\ParameterCount;

use JsonException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CodeSmell\ParameterCount\ConstructorOverinjectionRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\ParameterCount\LongParameterListRule;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * A service constructor is reported once, by `code-smell.constructor-overinjection`, through the
 * composed product: DI-registered rules, a real population session and the inline-directive audit.
 *
 * The run happens in an empty directory with the binary addressed absolutely, so that this
 * repository's own `qmx.yaml` cannot contribute findings to it.
 */
#[CoversClass(LongParameterListRule::class)]
#[CoversClass(ConstructorOverinjectionRule::class)]
final class ServiceConstructorRunTest extends TestCase
{
    private string $workingDirectory;

    protected function setUp(): void
    {
        $this->workingDirectory = \sprintf('%s/qmx_service_constructor_%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
        if (!mkdir($this->workingDirectory . '/src', 0o777, true) && !is_dir($this->workingDirectory . '/src')) {
            throw new RuntimeException('Failed to create the analysed directory');
        }
    }

    protected function tearDown(): void
    {
        // The run writes a `.qmx-cache/` into its working directory even under `--no-cache`.
        (new Process(['rm', '-rf', $this->workingDirectory]))->run();
    }

    /**
     * @return iterable<string, array{int, list<string>}>
     */
    public static function provideDependencyCounts(): iterable
    {
        yield 'six dependencies' => [6, []];
        yield 'seven dependencies' => [7, []];
        yield 'eight dependencies' => [8, ['code-smell.constructor-overinjection']];
    }

    /**
     * @param list<string> $expectedRules
     */
    #[Test]
    #[DataProvider('provideDependencyCounts')]
    public function itReportsAServiceConstructorOnlyThroughConstructorOverinjection(int $dependencies, array $expectedRules): void
    {
        $this->writeHandler($dependencies, '');

        self::assertSame($expectedRules, $this->constructorRules());
    }

    #[Test]
    public function itReportsALongParameterListDirectiveOnAServiceConstructorAsUnused(): void
    {
        $this->writeHandler(8, "    /**\n     * @qmx-ignore code-smell.long-parameter-list -- test\n     */\n");

        self::assertSame(
            ['annotation.unused-directive', 'code-smell.constructor-overinjection'],
            $this->constructorRules(),
        );
    }

    private function writeHandler(int $dependencies, string $docblock): void
    {
        $parameters = implode(', ', array_map(static fn(int $i): string => 'private Dependency $d' . $i, range(1, $dependencies)));
        file_put_contents(
            $this->workingDirectory . '/src/Handler.php',
            "<?php\n\nnamespace App;\n\ninterface Dependency {}\n\nfinal class Handler\n{\n{$docblock}    public function __construct({$parameters}) {}\n}\n",
        );
    }

    /**
     * @return list<string> sorted rule names of the findings this test is about
     */
    private function constructorRules(): array
    {
        $process = new Process([
            \PHP_BINARY,
            \dirname(__DIR__, 6) . '/bin/qmx',
            'check',
            $this->workingDirectory . '/src',
            '--workers=0',
            '--no-cache',
            '--no-progress',
            '--format=json',
            '--fail-on=none',
        ], $this->workingDirectory);
        $process->run();

        try {
            /** @var array{violations: list<array{rule: string}>} $report */
            $report = json_decode($process->getOutput(), true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            self::fail($exception->getMessage() . "\n" . $process->getErrorOutput());
        }

        $rules = [];
        foreach ($report['violations'] as $violation) {
            if (\in_array($violation['rule'], ['code-smell.constructor-overinjection', 'code-smell.long-parameter-list', 'annotation.unused-directive'], true)) {
                $rules[] = $violation['rule'];
            }
        }
        sort($rules);

        return $rules;
    }
}
