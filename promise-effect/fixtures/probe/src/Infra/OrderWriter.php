<?php

declare(strict_types=1);

namespace Probe\Infra;

use Probe\Domain\Order;
use Probe\Sub\Helper;
use Symfony\Component\Console\Command\Command;

final class OrderWriter extends Command
{
    private array $seen = [];

    public function write(Order $order, Helper $helper, bool $force): void
    {
        $this->seen[] = $order->id;

        if ($force) {
            $helper->touch($order->label);
        }
    }

    public function count(): int
    {
        return \count($this->seen);
    }
}
