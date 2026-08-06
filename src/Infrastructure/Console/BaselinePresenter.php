<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Baseline\BaselineCapture;
use Qualimetrix\Baseline\BaselineGenerator;
use Qualimetrix\Baseline\BaselineWriter;
use Qualimetrix\Configuration\ConfigurationProviderInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use Qualimetrix\Core\Violation\Violation;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Handles baseline generation when requested via CLI option.
 */
final readonly class BaselinePresenter
{
    public function __construct(
        private BaselineGenerator $baselineGenerator,
        private BaselineWriter $baselineWriter,
        private ConfigurationProviderInterface $configurationProvider,
    ) {}

    /**
     * Generates baseline file if requested.
     *
     * Returns true when a baseline was successfully written, false when skipped.
     *
     * @param list<Violation> $violations
     * @param list<AbsolutePath> $scope the analysed paths this run covered, recorded in the
     *                                  file so later commands can tell a narrower run from a
     *                                  wider one
     */
    public function generateBaselineIfRequested(
        array $violations,
        array $scope,
        InputInterface $input,
        OutputInterface $output,
    ): bool {
        $generateBaselinePath = $input->getOption('generate-baseline');
        if (!\is_string($generateBaselinePath) || $generateBaselinePath === '') {
            return false;
        }

        $projectRoot = $this->configurationProvider->getConfiguration()->projectRoot;
        $capture = $this->baselineGenerator->generate($violations, self::portableScope($scope, $projectRoot));
        $this->baselineWriter->write($capture->baseline, $generateBaselinePath, $projectRoot);

        $output->writeln(\sprintf(
            '<info>Baseline with %d entries written to %s</info>',
            $capture->baseline->count(),
            $generateBaselinePath,
        ));

        $this->reportUncaptured($capture, $output);

        return true;
    }

    /**
     * Names the findings the capture refused to record.
     *
     * Without this the success line above is the whole story a user gets, and
     * for a run whose findings are all on non-baselineable channels that
     * story is "Baseline with 0 entries written" followed by a `check` that
     * reports everything they thought they had just accepted.
     */
    private function reportUncaptured(BaselineCapture $capture, OutputInterface $output): void
    {
        if ($capture->uncaptured === []) {
            return;
        }

        $findings = 0;
        foreach ($capture->uncaptured as $group) {
            $findings += $group->memberCount;
        }

        $output->writeln(\sprintf(
            '<comment>%d finding(s) in %d group(s) were not recorded and will be reported again: %s</comment>',
            $findings,
            \count($capture->uncaptured),
            implode(', ', $capture->uncapturedChannels()),
        ));

        foreach (self::reasonCounts($capture) as $reason => $groups) {
            $output->writeln(\sprintf('<comment>  %d group(s): %s</comment>', $groups, $reason));
        }
    }

    /**
     * @return array<string, int>
     */
    private static function reasonCounts(BaselineCapture $capture): array
    {
        $counts = [];

        foreach ($capture->uncaptured as $group) {
            $reason = $group->reason->describe();
            $counts[$reason] = ($counts[$reason] ?? 0) + 1;
        }

        ksort($counts, \SORT_STRING);

        return $counts;
    }

    /**
     * Records the scope project-relatively where possible, so a baseline
     * committed by one developer means the same thing in another checkout.
     * A path outside the project root has no relative form and is kept as
     * given.
     *
     * @param list<AbsolutePath> $scope
     *
     * @return list<string>
     */
    private static function portableScope(array $scope, AbsolutePath $projectRoot): array
    {
        $paths = [];

        foreach ($scope as $path) {
            $relative = PathFactory::tryProjectRelative($path->value(), $projectRoot);
            $paths[] = $relative?->value() ?? $path->value();
        }

        return $paths;
    }
}
