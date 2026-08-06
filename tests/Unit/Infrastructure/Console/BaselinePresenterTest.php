<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Baseline\BaselineGenerator;
use Qualimetrix\Baseline\BaselineWriter;
use Qualimetrix\Configuration\AnalysisConfiguration;
use Qualimetrix\Configuration\ConfigurationHolder;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\Console\BaselinePresenter;
use Qualimetrix\Tests\Support\Time\FixedClock;
use Qualimetrix\Tests\Support\Violation\StubChannelDeclarationRegistry;
use Qualimetrix\Tests\Support\Violation\ViolationFactory;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;

#[CoversClass(BaselinePresenter::class)]
final class BaselinePresenterTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/qmx_presenter_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempDir . '/*');
        foreach ($files === false ? [] : $files as $file) {
            unlink($file);
        }

        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    #[Test]
    public function itReportsHowManyEntriesItWrote(): void
    {
        $output = $this->generate([
            ViolationFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'bar'), 25),
        ]);

        self::assertStringContainsString('Baseline with 1 entries written', $output);
    }

    /**
     * A group on a channel no rule declares never becomes an entry, so `check`
     * can never report it as inert either. Left unsaid, "Baseline with 0
     * entries written" reads as success and the very next `check` reports
     * findings the user believes they just accepted.
     */
    #[Test]
    public function itNamesTheFindingsItCouldNotRecordNextToTheSuccessLine(): void
    {
        $output = $this->generate([
            ViolationFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'bar'), 5, 'nobody.declares', 'this.channel'),
            ViolationFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'baz'), 7, 'nobody.declares', 'this.channel'),
        ]);

        self::assertStringContainsString('Baseline with 0 entries written', $output);
        self::assertStringContainsString('2 finding(s) in 2 group(s) were not recorded', $output);
        self::assertStringContainsString('nobody.declares#this.channel', $output);
        self::assertStringContainsString('no rule declares the channel', $output);
    }

    #[Test]
    public function itSaysNothingAboutRefusalsWhenThereWereNone(): void
    {
        $output = $this->generate([
            ViolationFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'bar'), 25),
        ]);

        self::assertStringNotContainsString('were not recorded', $output);
    }

    /**
     * @param list<\Qualimetrix\Core\Violation\Violation> $violations
     */
    private function generate(array $violations): string
    {
        $holder = new ConfigurationHolder();
        $holder->setConfiguration(new AnalysisConfiguration(
            projectRoot: AbsolutePath::fromString($this->tempDir),
        ));

        $presenter = new BaselinePresenter(
            new BaselineGenerator(StubChannelDeclarationRegistry::withDefaults(), new FixedClock()),
            new BaselineWriter(),
            $holder,
        );

        $output = new BufferedOutput();
        $input = new ArrayInput(
            ['--generate-baseline' => $this->tempDir . '/baseline.json'],
            new InputDefinition([new InputOption('generate-baseline', mode: InputOption::VALUE_OPTIONAL)]),
        );

        self::assertTrue($presenter->generateBaselineIfRequested($violations, [], $input, $output));

        return $output->fetch();
    }
}
