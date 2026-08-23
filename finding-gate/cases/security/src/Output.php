<?php

namespace Corpus\Security;

class Output
{
    public function greet(): void
    {
        echo "<div>" . $_GET['name'] . "</div>";
    }
}
