<?php

use App\Licensing\LicensingServiceProvider;
use App\Providers\AccessServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\CatalogServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HelpServiceProvider;
use App\Providers\NotificationsServiceProvider;

return [
    AppServiceProvider::class,
    AccessServiceProvider::class,
    FortifyServiceProvider::class,
    LicensingServiceProvider::class,
    NotificationsServiceProvider::class,
    HelpServiceProvider::class,
    CatalogServiceProvider::class,
];
