<?php

namespace Mmedia\LaravelChat\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Mmedia\LaravelChat\LaravelChat
 */
class LaravelChat extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Mmedia\LaravelChat\LaravelChat::class;
    }
}
