<?php

declare(strict_types=1);

namespace Fixtures\AnonymousInheritanceSample\Host\AnonAttribute;

use Fixtures\AnonymousInheritanceSample\Marker\Mark;
use Fixtures\AnonymousInheritanceSample\Sink\Sink;

/**
 * Carries no attribute itself. #[Mark] sits on the nested anonymous class —
 * that fact belongs to the anonymous class, not to AnonAttributeHost.
 */
final class AnonAttributeHost
{
    public function build(): object
    {
        return new #[Mark] class {};
    }

    public function useSink(Sink $sink): void {}
}
