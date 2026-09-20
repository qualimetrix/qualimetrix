<?php

namespace Corpus\DuplicateDeclaration;

// The polyfill shape: this declaration and the one in ShimNative.php share a
// name, and only one of them exists in any run. Its own parent is Base, so its
// depth is 1 -- a fact about this declaration, not about the name.
if (!class_exists(Shim::class)) {
    class Shim extends Base
    {
    }
}
