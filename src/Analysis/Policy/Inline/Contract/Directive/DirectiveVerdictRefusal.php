<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Contract\Directive;

use Qualimetrix\Analysis\Finding\Contract\FindingChannel;

/** A refusal's public wording and internal selection address. */
final readonly class DirectiveVerdictRefusal
{
    public function __construct(
        public FindingChannel $channel,
        public string $message,
        public ?string $addressedProducer,
    ) {}
}
