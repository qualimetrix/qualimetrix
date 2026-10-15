<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Support;

use Qualimetrix\Infrastructure\Console\CheckReport\FormatterContextFactory;
use Qualimetrix\Infrastructure\Console\CheckReport\ReportAssembler;
use Qualimetrix\Infrastructure\Console\CheckReport\ReportFormatter;
use Qualimetrix\Infrastructure\Console\RuntimePolicy\ExitCodeResolver;
use Qualimetrix\Infrastructure\Console\Terminal\ReportPublication\ReportPublication;

final readonly class ReportPresentationFixture
{
    public function __construct(public ReportAssembler $assembler, public ReportFormatter $formatter, public FormatterContextFactory $contexts, public ExitCodeResolver $exit, public ReportPublication $publication) {}
}
