<?php

namespace Corpus\DuplicateDeclaration;

// The NOC half: this name is declared twice, both times extending Base, so the
// hierarchy has one subclass called Twin however many files declare it.
class Twin extends Base
{
}
