<?php

declare(strict_types=1);

namespace {
    final class Plain {}
}

namespace App\Domain {
    interface Port
    {
        public function call(): int;
    }
}

namespace App\Cx {
    interface Greeter
    {
        public function greet(): string;
    }

    trait Greeting
    {
        public function greet(): string
        {
            return 'hello';
        }
    }

    enum Status: string
    {
        case Ready = 'ready';
    }

    final class Service implements Greeter
    {
        use Greeting;
    }
}
