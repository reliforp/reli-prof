<?php declare(strict_types = 1);

trait TraitUsesSelf
{
    use TraitUsesSelf;
}

class Foo
{
    use TraitUsesSelf;
}
