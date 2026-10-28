<?php

namespace App\Workflow\Catalogue;

/**
 * Fixed display groups inside a stage (domain 03): categories of actions, not stage types or departments.
 */
enum ActionGroup: string
{
    case InfoMeasurement = 'info_measurement';
    case AgreementApproval = 'agreement_approval';
    case ExecutionSupply = 'execution_supply';
    case Finance = 'finance';
    case Delivery = 'delivery';
}
