<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\CheckReport;

use Qualimetrix\Infrastructure\Console\CheckReport\FormatBinding\FormatterContextFactory;
use Qualimetrix\Infrastructure\Console\CheckReport\Projection\CheckFindingProjection;

use Qualimetrix\Infrastructure\Console\Profile\ProfilePresenter;
use Qualimetrix\Infrastructure\Console\RunTarget\RunTargetSession;
use Qualimetrix\Infrastructure\Console\Terminal\ReportPublication\ReportPublication;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** @qmx-threshold coupling.instability 0.818183 -- This subject implements an owner-declared outward protocol with intentionally few incoming readers; the finite boundary preserves visibility of additional dependencies. */
final readonly class CheckReportDelivery
{
    public function __construct(private FormatterContextFactory $contexts, private CheckFindingProjection $projection, private CheckReportRenderer $renderer, private ReportPublication $publication, private ProfilePresenter $profile) {}

    public function prepare(InputInterface $input, OutputInterface $output, RunTargetSession $session): CheckResponse
    {
        $this->publication->assertOutputIsWritable($input, $session->targets());
        $namespace = $this->contexts->bindBeforeAnalysis($input);
        return new CheckResponse($input, $output, $session, $this->contexts, $this->projection, $this->renderer, $this->publication, $this->profile, $namespace);
    }
}
