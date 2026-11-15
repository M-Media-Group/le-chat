<?php

use Illuminate\Database\Eloquent\Model;

arch('chat package source does not use debugging functions')
    ->expect('Mmedia\\LeChat')
    ->not->toUse(['dd', 'dump', 'ray']);

arch('chat models extend Eloquent models')
    ->expect('Mmedia\\LeChat\\Models')
    ->toBeClasses()
    ->toExtend(Model::class);
