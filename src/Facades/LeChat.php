<?php

namespace Mmedia\LeChat\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Mmedia\LeChat\LeChat
 */
class LeChat extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Mmedia\LeChat\LeChat::class;
    }
}
