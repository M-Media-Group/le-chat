<?php

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

arch()
    ->expect('App')
    ->toUseStrictTypes()
    ->not->toUse(['die', 'dd', 'dump']);
arch()
    ->expect('App\Models')
    ->toBeClasses()
    ->toExtend('Illuminate\Database\Eloquent\Model')
    ->toOnlyBeUsedIn('App\Repositories')
    ->ignoring('App\Models\User');
arch()
    ->expect('App\Http')
    ->toOnlyBeUsedIn('App\Http');
arch()
    ->expect('App\*\Traits')
    ->toBeTraits();
arch()->preset()->php();
arch()->preset()->security()->ignoring('md5');
