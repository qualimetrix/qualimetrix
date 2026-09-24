<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Application;
use Qualimetrix\Infrastructure\Console\Command\BaselineGenerateCommand;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\Console\Command\DirectivesCommand;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter;
use Qualimetrix\Infrastructure\Console\RuleInputValidator;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `--help` and the selector grammar describe the same language.
 *
 * The grammar has two forms — an exact name and `NAME.*` — and a bare group
 * prefix is neither. Help that offers `complexity` as an example sends the
 * reader straight into a refusal, so every example printed for the two
 * selection options is executed here, and a bare group name is checked to be
 * refused with the spelling that would have worked.
 */
#[CoversClass(RuleInputValidator::class)]
final class RuleSelectorHelpGrammarTest extends TestCase
{
    private const string FIXTURE = 'tests/Infrastructure/Console/Fixtures/parses_with_no_findings.php';

    /** @return iterable<string, array{class-string<Command>, string}> */
    public static function provideSelectionOptions(): iterable
    {
        foreach ([CheckCommand::class, DirectivesCommand::class, BaselineGenerateCommand::class] as $command) {
            foreach (['only-rule', 'disable-rule'] as $option) {
                yield \sprintf('%s --%s', substr($command, (int) strrpos($command, '\\') + 1), $option) => [$command, $option];
            }
        }
    }

    /** @param class-string<Command> $commandClass */
    #[Test]
    #[DataProvider('provideSelectionOptions')]
    public function itOffersOnlyExamplesTheSelectorGrammarAccepts(string $commandClass, string $option): void
    {
        $container = (new ContainerFactory())->create();
        /** @var Command $command */
        $command = $container->get($commandClass);
        $description = $command->getDefinition()->getOption($option)->getDescription();

        self::assertSame(1, preg_match('/\(e\.g\., ([^)]+)\)/', $description, $match), 'The help names no example to check.');
        $examples = array_map(trim(...), explode(',', $match[1]));
        self::assertNotSame([], $examples);
        self::assertStringNotContainsString('by prefix', $description);

        foreach ($examples as $example) {
            $tester = $this->checkTester();
            $tester->execute(
                ['paths' => [self::FIXTURE], '--format' => 'json', '--' . $option => [$example]],
                ['capture_stderr_separately' => true],
            );

            self::assertNotSame(
                3,
                $tester->getStatusCode(),
                \sprintf('Help example "%s" for --%s is refused: %s', $example, $option, $tester->getDisplay()),
            );
        }
    }

    /**
     * Every group the registry lists, measured: a bare group name selects
     * nothing and is refused, and the refusal names the `NAME.*` spelling
     * whenever that spelling would have selected something.
     *
     * `computed` is not in this list on purpose: it is the name of a producer,
     * so the bare name is an exact selector and is accepted.
     *
     * @return iterable<string, array{string}>
     */
    public static function provideGroupNames(): iterable
    {
        foreach ([
            'annotation', 'architecture', 'code-smell', 'cohesion', 'complexity', 'coupling', 'design', 'discovery',
            'duplication', 'health', 'maintainability', 'security', 'size', 'suppression',
        ] as $group) {
            yield $group => [$group];
        }
    }

    #[Test]
    #[DataProvider('provideGroupNames')]
    public function itRefusesABareGroupNameAndNamesTheStarSpelling(string $group): void
    {
        $tester = $this->checkTester();
        $tester->execute(
            ['paths' => [self::FIXTURE], '--format' => 'json', '--only-rule' => [$group]],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(3, $tester->getStatusCode());
        $error = self::envelopeError($tester);
        self::assertStringNotContainsString('group, or channel', $error);
        self::assertStringContainsString(\sprintf('write "%s.*"', $group), $error);

        $starred = $this->checkTester();
        $starred->execute(
            ['paths' => [self::FIXTURE], '--format' => 'json', '--only-rule' => [$group . '.*']],
            ['capture_stderr_separately' => true],
        );
        self::assertNotSame(3, $starred->getStatusCode(), $starred->getDisplay());
    }

    #[Test]
    public function itAcceptsTheBareNameOfAProducerThatSharesItsFirstSegmentWithNothing(): void
    {
        $tester = $this->checkTester();
        $tester->execute(
            ['paths' => [self::FIXTURE], '--format' => 'json', '--only-rule' => ['computed']],
            ['capture_stderr_separately' => true],
        );

        self::assertNotSame(3, $tester->getStatusCode(), $tester->getDisplay());
    }

    #[Test]
    public function itGivesNoStarHintWhenTheStarSpellingWouldMatchNothingEither(): void
    {
        $tester = $this->checkTester();
        $tester->execute(
            ['paths' => [self::FIXTURE], '--format' => 'json', '--only-rule' => ['nosuchgroup']],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(3, $tester->getStatusCode());
        $error = self::envelopeError($tester);
        self::assertStringContainsString('Rule selector "nosuchgroup" does not match any registered producer or channel.', $error);
        self::assertStringNotContainsString('.*', $error);
    }

    private static function envelopeError(CommandTester $tester): string
    {
        /** @var array{error: string, exit_code: int} $envelope */
        $envelope = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);

        return $envelope['error'];
    }

    private function checkTester(): CommandTester
    {
        $container = (new ContainerFactory())->create();
        /** @var CheckCommand $command */
        $command = $container->get(CheckCommand::class);
        /** @var RefusalPresenter $refusalPresenter */
        $refusalPresenter = $container->get(RefusalPresenter::class);
        $application = new Application(new ErrorStream(), $refusalPresenter);
        $application->addCommand($command);

        return new CommandTester($command);
    }
}
