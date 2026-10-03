<?php

namespace Corpus\Smells;

class Smells
{
    public function report(string $input, array $items): void
    {
        eval('1;');
        var_dump($input);
        for ($i = 0; $i < count($items); $i++) {
            echo $i;
        }
        try {
            echo $input;
        } catch (\Throwable $e) {
        }
        if ($input === $input) {
            echo 'always';
        }
        goto finish;
        finish:
        exit(1);
    }
}

$input = 'top';
$items = [1];
eval('1;');
var_dump($input);
for ($i = 0; $i < count($items); $i++) {
    echo $i;
}
try {
    echo $input;
} catch (\Throwable $e) {
}
@file_get_contents('missing');
if ($input === $input) {
    echo 'always';
}
goto top_finish;
top_finish:
exit(1);
