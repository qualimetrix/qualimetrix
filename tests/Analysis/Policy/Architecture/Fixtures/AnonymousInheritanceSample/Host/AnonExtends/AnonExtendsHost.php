<?php

declare(strict_types=1);

namespace Fixtures\AnonymousInheritanceSample\Host\AnonExtends;

use Fixtures\AnonymousInheritanceSample\Marker\L1;
use Fixtures\AnonymousInheritanceSample\Sink\Sink;

/**
 * Declares no parent of its own. The nested anonymous class extends L1 (and
 * transitively L0) — that ancestry belongs to the anonymous class, not to
 * AnonExtendsHost.
 */
final class AnonExtendsHost
{
    public function build(): object
    {
        return new class extends L1 {};
    }

    public function useSink(Sink $sink): void {}
}
