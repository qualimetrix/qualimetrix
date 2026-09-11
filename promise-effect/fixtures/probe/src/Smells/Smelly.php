<?php

declare(strict_types=1);

namespace Probe\Smells;

class Smelly
{
    private int $never = 0;

    public function __construct(
        public int $a1,
        public int $a2,
        public int $a3,
        public int $a4,
        public int $a5,
        public int $a6,
        public int $a7,
        public int $a8,
        public int $a9,
    ) {}

    public function loop(array $items): int
    {
        $total = 0;

        for ($i = 0; $i < \count($items); ++$i) {
            $total += $i;
        }

        return $total;
    }

    public function spew(mixed $value): void
    {
        var_dump($value);
        print_r($value);
    }

    public function swallow(): void
    {
        try {
            $this->spew(1);
        } catch (\Throwable) {
        }
    }

    public function evaluate(string $code): mixed
    {
        return eval($code);
    }

    public function leave(): void
    {
        exit(0);
    }

    public function jump(): int
    {
        $i = 0;
        start:
        ++$i;

        if ($i < 2) {
            goto start;
        }

        return $i;
    }

    public function same(int $x): bool
    {
        return $x > 1 && $x > 1;
    }

    public function globals(): string
    {
        return (string) ($_GET['q'] ?? '') . (string) ($_POST['p'] ?? '') . (string) ($_SERVER['HTTP_HOST'] ?? '');
    }

    public function dead(): int
    {
        return 1;

        $unused = 2;

        return $unused;
    }

    private function unusedPrivate(): int
    {
        return $this->never;
    }
}
