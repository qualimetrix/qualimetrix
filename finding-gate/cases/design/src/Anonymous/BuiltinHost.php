<?php

namespace Corpus\Design\Anonymous;

// The anonymous class extends a PHP builtin. DependencyGraphBuilder keeps a
// builtin-parent edge only when its type is Extends, so this is the only
// witness that the fix does not delete that edge while it stops the
// enclosing class from inheriting the anonymous class's own declaration.
class BuiltinHost
{
    public function build(): object
    {
        return new class extends \ArrayObject {
        };
    }
}
