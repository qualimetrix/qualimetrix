<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Support;

use Qualimetrix\Infrastructure\Console\CheckReport\Assembly\ReportAssembler;
use Qualimetrix\Infrastructure\Console\CheckReport\FormatBinding\FormatterContextFactory;
use Qualimetrix\Infrastructure\Console\CheckReport\FormatBinding\ReportFormatter;
use Qualimetrix\Infrastructure\Console\RuntimePolicy\ExitCodeResolver;
use Qualimetrix\Infrastructure\Console\Terminal\ReportPublication\ReportPublication;

final readonly class ReportPresentationFixture
{
    public function __construct(public ReportAssembler $assembler, public ReportFormatter $formatter, public FormatterContextFactory $contexts, public ExitCodeResolver $exit, public ReportPublication $publication) {}
}
