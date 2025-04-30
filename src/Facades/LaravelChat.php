<?php

namespace MMedia\LaravelChat\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \MMedia\LaravelChat\LaravelChat
 */
class LaravelChat extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \MMedia\LaravelChat\LaravelChat::class;
    }
}
