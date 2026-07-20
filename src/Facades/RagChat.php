<?php

namespace Awais\RagChat\Facades;

use Illuminate\Support\Facades\Facade;

class RagChat extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Awais\RagChat\RagChat::class;
    }
}
