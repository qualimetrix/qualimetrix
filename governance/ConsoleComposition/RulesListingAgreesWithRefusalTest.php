<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\ConsoleComposition;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Finding\Contract\Rule\FrameworkOptionKeys;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionSurface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionKeyRecognition;
use Qualimetrix\Infrastructure\Console\Command\RulesCommand;
use Qualimetrix\Infrastructure\Console\RuleListingPresenter;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The listing advertises exactly the options the refusal admits, for every
 * registered producer and every level slot.
 *
 * **What this is for, since both sides now read one declaration and the
 * comparison of sets is therefore close to tautological.** It is a wiring
 * check, and each of these was a real way to be wrong before the two sides
 * shared an owner: a listing that forgets a producer's level slots, a
 * classless producer enumerated by `allRules()` and skipped by the renderer, a
 * footer that stops matching {@see FrameworkOptionKeys}, a rule whose
 * `enabled` is answered-by-the-class rather than accepted and must therefore
 * not be advertised. The defect it exists to prevent is the one it was written
 * after: 54 of 132 options reachable only through `--rule-opt` and named
 * nowhere the product points a reader.
 *
 * Both sides are taken from the live container per run. A committed table would
 * green as the product grows — the failure that let the gap reach a release in
 * the first place.
 */
#[CoversClass(RulesCommand::class)]
#[CoversClass(RuleListingPresenter::class)]
#[CoversClass(RuleOptionSurface::class)]
final class RulesListingAgreesWithRefusalTest extends TestCase
{
    #[Test]
    public function itAdvertisesExactlyWhatTheRefusalAdmitsAtEveryDepth(): void
    {
        $container = (new ContainerFactory())->create();

        $execution = $container->get(RuleExecutionInterface::class);
        \assert($execution instanceof RuleExecutionInterface);

        $command = $container->get(RulesCommand::class);
        \assert($command instanceof RulesCommand);

        $tester = new CommandTester($command);
        $tester->execute([]);

        $display = $tester->getDisplay();
        $listed = self::optionsPerDepth($display);

        // Read back rather than taken from `FrameworkOptionKeys::all()`: the
        // footer is what a reader actually gets, and a reassembly built from
        // the owner would agree with itself no matter what the command printed.
        // Measured — a planted footer naming one key of three left this test
        // green until it was read from the display instead.
        $footer = self::footerKeys($display);

        self::assertSame(
            FrameworkOptionKeys::all(),
            $footer,
            'The footer must name every framework key, since no rule body does.',
        );
        $producers = $execution->allRules();

        self::assertNotSame([], $producers, 'The production container must register producers.');

        foreach ($producers as $producer) {
            $surface = RuleOptionSurface::of($producer->optionsClass);

            // Reassembled rather than subtracted: the listing splits the
            // refusal's one set across three places — the options line, one
            // line per level slot, and the footer — and putting it back
            // together is what shows nothing was dropped on the way. Comparing
            // only the options line would let a lost slot pass as a narrower
            // set legitimately printed elsewhere.
            $reassembled = [
                ...($listed[$producer->name]['-'] ?? []),
                ...$surface->levels(),
                ...$footer,
            ];
            sort($reassembled);

            self::assertSame(
                self::refusedAt($producer->name, $producer->optionsClass, null),
                $reassembled,
                \sprintf('Rule "%s" advertises a different set than its refusal admits.', $producer->name),
            );

            foreach ($surface->levels() as $level) {
                self::assertSame(
                    self::refusedAt($producer->name, $producer->optionsClass, $level),
                    $listed[$producer->name][$level] ?? [],
                    \sprintf('Rule "%s" at level "%s" advertises a different set.', $producer->name, $level),
                );
            }

            self::assertSame(
                $surface->levels(),
                array_values(array_diff(array_keys($listed[$producer->name] ?? []), ['-'])),
                \sprintf('The listing names different levels than rule "%s" declares.', $producer->name),
            );
        }
    }

    /**
     * Every CLI alias points at a key some declaration accepts.
     *
     * A guard rather than a repair — all of them do today — and the thing it
     * guards is an alias quietly outliving the option it names, which the
     * listing would then advertise as a way to reach something that no longer
     * exists.
     */
    #[Test]
    public function itResolvesEveryCliAliasToAnAcceptedOption(): void
    {
        $container = (new ContainerFactory())->create();

        $execution = $container->get(RuleExecutionInterface::class);
        \assert($execution instanceof RuleExecutionInterface);

        $checked = 0;

        foreach ($execution->allRules() as $producer) {
            $surface = RuleOptionSurface::of($producer->optionsClass);

            foreach ($producer->aliases as $alias => $target) {
                ++$checked;

                self::assertNotNull(
                    $surface->locate($target),
                    \sprintf('Alias --%s of rule "%s" targets "%s", which nothing accepts.', $alias, $producer->name, $target),
                );
            }
        }

        self::assertGreaterThan(0, $checked, 'No alias was checked, so this proved nothing.');
    }

    /**
     * The allowed set the refusal names at one depth, read off the carrier
     * rather than out of its sentence.
     *
     * @param class-string<\Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface> $optionsClass
     *
     * @return list<string>
     */
    private static function refusedAt(string $ruleName, string $optionsClass, ?string $level): array
    {
        $written = $level === null
            ? ['zzNotAnOption' => 1]
            : [$level => ['zzNotAnOption' => 1]];

        try {
            RuleOptionKeyRecognition::refuseUnknownKeys($written, $ruleName, $optionsClass);
        } catch (ConfigurationRefusal $refusal) {
            return $refusal->position()?->accepted() ?? [];
        }

        self::fail(\sprintf('Rule "%s" accepted an option nobody declares at depth "%s".', $ruleName, $level ?? '-'));
    }

    /**
     * The framework keys the footer advertises, in the order it prints them.
     *
     * @return list<string>
     */
    private static function footerKeys(string $display): array
    {
        foreach (explode("\n", $display) as $line) {
            if (preg_match('/^Every rule also takes: (.+)$/', $line, $match) === 1) {
                return array_map(trim(...), explode(',', $match[1]));
            }
        }

        self::fail('The listing printed no footer naming the framework keys.');
    }

    /**
     * The rendered listing read back as rule => depth => options, where the
     * rule's own depth is keyed `-`.
     *
     * @return array<string, array<string, list<string>>>
     */
    private static function optionsPerDepth(string $display): array
    {
        $listed = [];
        $rule = null;

        foreach (explode("\n", $display) as $line) {
            if (preg_match('/^ {2}(\S+)\s{2,}\S/', $line, $match) === 1) {
                $rule = $match[1];
                $listed[$rule] ??= [];

                continue;
            }

            if ($rule === null) {
                continue;
            }

            if (preg_match('/^ {4}options: (.+)$/', $line, $match) === 1) {
                $listed[$rule]['-'] = array_map(trim(...), explode(',', $match[1]));

                continue;
            }

            // `(.*)` rather than `(.+)`: the presenter prints a slot's line
            // even for a slot that accepts nothing, which is a branch it
            // declares and this parser used to drop — the level would go
            // missing from the reading and the test would fail as a
            // disagreement rather than report the empty slot.
            if (preg_match('/^ {4}options at (\S+): ?(.*)$/', $line, $match) === 1) {
                $listed[$rule][$match[1]] = $match[2] === ''
                    ? []
                    : array_map(trim(...), explode(',', $match[2]));
            }
        }

        return $listed;
    }
}
