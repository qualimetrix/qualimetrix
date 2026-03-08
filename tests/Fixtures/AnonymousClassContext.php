<?php

declare(strict_types=1);

namespace App\Service;

class OuterClass
{
    public function beforeAnonymous(): void
    {
        $x = 1;
    }

    public function methodWithAnonymous(): void
    {
        $handler = new class {
            public function innerMethod(): void
            {
                if (true) {
                    echo 'inner';
                }
            }
        };
    }

    public function afterAnonymous(): int
    {
        if (true) {
            return 1;
        }
        return 0;
    }
}
