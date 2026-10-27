<?php

use App\Licensing\LicensingServiceProvider;
use App\Providers\AccessServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\NotificationsServiceProvider;
use App\Providers\HelpServiceProvider;

return [
    AppServiceProvider::class,
    AccessServiceProvider::class,
    FortifyServiceProvider::class,
    LicensingServiceProvider::class,
    NotificationsServiceProvider::class,
    HelpServiceProvider::class,
];
