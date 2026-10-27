<?php

use App\Licensing\LicensingServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    LicensingServiceProvider::class,
];
