<?php

namespace App\Reports\Definitions;

use App\Access\Permission;
use App\Reports\ReportResult;
use App\Reports\ReportScope;

interface Report
{
    public function key(): string;

    /** Grant needed besides P-60 (the whole report is money, e.g. refunds need P-37); null = basic report. */
    public function requires(): ?Permission;

    /** License capability, or null when every plan has it. */
    public function capability(): ?string;

    public function build(ReportScope $scope): ReportResult;
}
