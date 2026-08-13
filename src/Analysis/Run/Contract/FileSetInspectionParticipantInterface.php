<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract;

use SplFileInfo;

interface FileSetInspectionParticipantInterface
{
    /** @return non-empty-string */
    public static function participantId(): string;

    /** @return non-empty-string */
    public static function producerRuleName(): string;

    public function resetForRun(): void;

    /** @param list<SplFileInfo> $eligibleFiles */
    public function inspect(array $eligibleFiles): void;
}
