<?php

namespace Corpus\Design\Model;

class Order
{
    private array $lines = [];
    private string $reference = '';
    private int $total = 0;

    public function add(Product $product, int $quantity): void
    {
        $this->lines[] = [$product->getSku(), $quantity];
        $this->total += $product->getPrice() * $quantity;
    }

    public function reference(): string
    {
        return $this->reference;
    }

    public function total(): int
    {
        return $this->total;
    }

    public function lineCount(): int
    {
        return count($this->lines);
    }
}
