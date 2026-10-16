<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Parallel;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use Qualimetrix\Analysis\Evidence\Cohesion\Contract\LcomCollectionConfiguration;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\RuleOptionDocumentFormsInterface;
use Qualimetrix\Core\Path\AbsolutePath;

/**
 * @implements Task<bool, mixed, mixed>
 *
 * @qmx-threshold coupling.instability warning=0.800001 -- The bootstrap probe carries the same serialized composition and run settings as a real task for its few worker-pool consumers; splitting the request would transfer those dependencies.
 */
final readonly class WorkerProbeTask implements Task
{
    public function __construct(private AbsolutePath $projectRoot, private WorkerComposition $composition, private RuleOptionDocumentFormsInterface $documentForms, private ?AbsolutePath $cacheDir, private LcomCollectionConfiguration $lcomConfiguration) {}

    public function run(Channel $channel, Cancellation $cancellation): bool
    {
        $cancellation->throwIfRequested();
        WorkerBootstrap::getFileProcessor($this->projectRoot, $this->composition, $this->documentForms, $this->cacheDir, $this->lcomConfiguration);
        return true;
    }
}
