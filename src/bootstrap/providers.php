<?php

use App\Licensing\LicensingServiceProvider;
use App\Providers\AccessServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\CatalogServiceProvider;
use App\Providers\CommissionsServiceProvider;
use App\Providers\FinanceServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HelpServiceProvider;
use App\Providers\InventoryServiceProvider;
use App\Providers\NotificationsServiceProvider;
use App\Providers\ReturnsServiceProvider;
use App\Providers\SettlementServiceProvider;
use App\Providers\WorkflowServiceProvider;

return [
    AppServiceProvider::class,
    AccessServiceProvider::class,
    FortifyServiceProvider::class,
    LicensingServiceProvider::class,
    NotificationsServiceProvider::class,
    HelpServiceProvider::class,
    CatalogServiceProvider::class,
    WorkflowServiceProvider::class,
    FinanceServiceProvider::class,
    CommissionsServiceProvider::class,
    SettlementServiceProvider::class,
    InventoryServiceProvider::class,
    ReturnsServiceProvider::class,
];
