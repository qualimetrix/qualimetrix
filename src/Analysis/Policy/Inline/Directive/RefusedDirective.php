<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Directive;

use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveSite;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveVerdictRefusal;

final readonly class RefusedDirective
{
    public function __construct(
        public DirectiveSite $site,
        public DirectiveRefusalChannel $channel,
        public string $message,
        public ?string $hint = null,
        public ?string $addressedProducer = null,
    ) {}

    public function publicRefusal(): DirectiveVerdictRefusal
    {
        return new DirectiveVerdictRefusal(new FindingChannel($this->channel->value), $this->message, $this->addressedProducer);
    }
}
