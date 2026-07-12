<?php

use TriggerEngage\Server\Providers\AppServiceProvider;
use TriggerEngage\Server\Providers\HorizonServiceProvider;
use TriggerEngage\Server\Providers\TriggerEngageServiceProvider;

return [
    AppServiceProvider::class,
    HorizonServiceProvider::class,
    TriggerEngageServiceProvider::class,
];
