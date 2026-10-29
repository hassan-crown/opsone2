<?php

namespace App\Reports;

use InvalidArgumentException;

/**
 * The single definition of every number a report shows (SHR-12, ADR-0026, docs/p9-metrics.md). A report may only emit
 * a metric registered here (`ReportResult::metric()` refuses an unknown key), so a figure has one formula, one set of
 * source tables and one VAT treatment however many screens or exports show it.
 *
 * kind: count | money (halalas) | quantity (thousandths) | hours | percent
 * vat:  gross (VAT included, as the source stores it) | net | none
 * grant: extra permission beyond P-60 needed to see the value (money kinds are omitted, never shown as 0)
 */
final class MetricCatalogue
{
    /**
     * @var array<string, array{kind: string, vat: string, grant: string|null, sources: string, formula: string}>
     */
    public const DEFINITIONS = [
        'sales.orders_created' => ['kind' => 'count', 'vat' => 'none', 'grant' => null, 'sources' => 'orders', 'formula' => 'Orders created in range.'],
        'sales.interests_converted' => ['kind' => 'count', 'vat' => 'none', 'grant' => null, 'sources' => 'interests', 'formula' => 'Interests (branch in scope) whose one-time conversion converted_at falls in range.'],
        'sales.agreements_approved' => ['kind' => 'count', 'vat' => 'none', 'grant' => null, 'sources' => 'agreements', 'formula' => 'Orders whose first agreement version was approved in range.'],
        'sales.agreed_gross_minor' => ['kind' => 'money', 'vat' => 'gross', 'grant' => 'P-37', 'sources' => 'agreements', 'formula' => 'Sum of the ACTIVE agreement total of orders first approved in range, excluding cancelled orders.'],
        'sales.agreed_net_minor' => ['kind' => 'money', 'vat' => 'net', 'grant' => 'P-37', 'sources' => 'agreements', 'formula' => 'Same set, sum of net_minor.'],
        'sales.agreed_vat_minor' => ['kind' => 'money', 'vat' => 'none', 'grant' => 'P-37', 'sources' => 'agreements', 'formula' => 'Same set, sum of vat_minor.'],
        'sales.completed' => ['kind' => 'count', 'vat' => 'none', 'grant' => null, 'sources' => 'state_transitions(M-04,M-05)', 'formula' => 'Orders whose transition to execution_finished (custom) or delivered (ready) occurred in range.'],
        'sales.cancelled' => ['kind' => 'count', 'vat' => 'none', 'grant' => null, 'sources' => 'state_transitions(M-04,M-05)', 'formula' => 'Orders whose transition to cancelled occurred in range.'],
        'sales.open_orders' => ['kind' => 'count', 'vat' => 'none', 'grant' => null, 'sources' => 'orders', 'formula' => 'Orders now in an active status (not draft, cancelled, closed, delivered, execution_finished).'],

        'collection.collected_minor' => ['kind' => 'money', 'vat' => 'gross', 'grant' => 'P-37', 'sources' => 'money_entries, money_journals', 'formula' => 'Sum of entries of collection journals created in range.'],
        'collection.corrections_minor' => ['kind' => 'money', 'vat' => 'gross', 'grant' => 'P-37', 'sources' => 'money_entries, money_journals', 'formula' => 'Sum of entries of reversal journals in range (negative).'],
        'collection.transfers_minor' => ['kind' => 'money', 'vat' => 'gross', 'grant' => 'P-37', 'sources' => 'money_entries, money_journals', 'formula' => 'Sum of entries of transfer journals in range, on in-scope orders.'],
        'collection.refunded_minor' => ['kind' => 'money', 'vat' => 'gross', 'grant' => 'P-37', 'sources' => 'money_entries, money_journals', 'formula' => 'Sum of entries of refund journals in range (negative: money that left).'],
        'collection.net_minor' => ['kind' => 'money', 'vat' => 'gross', 'grant' => 'P-37', 'sources' => 'money_entries, money_journals', 'formula' => 'Sum of every entry in range = collected + corrections + transfers + refunded.'],
        'collection.overdue_minor' => ['kind' => 'money', 'vat' => 'gross', 'grant' => 'P-37', 'sources' => 'order_dues, money_entries', 'formula' => 'Remaining (due − allocated) of dues whose derived date is before today.'],
        'collection.open_minor' => ['kind' => 'money', 'vat' => 'gross', 'grant' => 'P-37', 'sources' => 'order_dues, money_entries', 'formula' => 'Remaining of all open dues (aged or not).'],

        'refunds.requested_count' => ['kind' => 'count', 'vat' => 'none', 'grant' => 'P-37', 'sources' => 'refunds', 'formula' => 'Refunds created in range.'],
        'refunds.executed_count' => ['kind' => 'count', 'vat' => 'none', 'grant' => 'P-37', 'sources' => 'refunds', 'formula' => 'Refunds executed in range.'],
        'refunds.executed_minor' => ['kind' => 'money', 'vat' => 'gross', 'grant' => 'P-37', 'sources' => 'refunds', 'formula' => 'Sum of amount of refunds with status executed and executed_at in range.'],
        'refunds.open_minor' => ['kind' => 'money', 'vat' => 'gross', 'grant' => 'P-37', 'sources' => 'refunds', 'formula' => 'Sum of refunds now requested, in progress or unresolved.'],
        'refunds.corrections_count' => ['kind' => 'count', 'vat' => 'none', 'grant' => 'P-37', 'sources' => 'payment_corrections', 'formula' => 'Payment corrections made in range.'],
        'refunds.open_disputes' => ['kind' => 'count', 'vat' => 'none', 'grant' => 'P-37', 'sources' => 'financial_disputes', 'formula' => 'Disputes not yet resolved.'],

        'commissions.accrued_minor' => ['kind' => 'money', 'vat' => 'none', 'grant' => 'P-94', 'sources' => 'commission_entries', 'formula' => 'Sum of signed entries created in range (corrections included, never netted away).'],
        'commissions.paid_minor' => ['kind' => 'money', 'vat' => 'none', 'grant' => 'P-94', 'sources' => 'commission_payouts', 'formula' => 'Sum of payouts with status paid and paid_on in range.'],
        'commissions.balance_minor' => ['kind' => 'money', 'vat' => 'none', 'grant' => 'P-94', 'sources' => 'commission_entries, commission_payouts', 'formula' => 'All entries to the end of range minus payouts paid to the end of range.'],

        'workflow.tasks_completed' => ['kind' => 'count', 'vat' => 'none', 'grant' => null, 'sources' => 'tasks', 'formula' => 'Tasks completed in range.'],
        'workflow.avg_completion_hours' => ['kind' => 'hours', 'vat' => 'none', 'grant' => null, 'sources' => 'tasks', 'formula' => 'Mean of completed_at − created_at in hours over those tasks.'],
        'workflow.tasks_overdue' => ['kind' => 'count', 'vat' => 'none', 'grant' => null, 'sources' => 'tasks', 'formula' => 'Open tasks with a due date before now (no due date is never overdue).'],
        'workflow.stages_completed' => ['kind' => 'count', 'vat' => 'none', 'grant' => null, 'sources' => 'order_stages', 'formula' => 'Stages completed in range.'],

        'appointments.scheduled' => ['kind' => 'count', 'vat' => 'none', 'grant' => null, 'sources' => 'appointments', 'formula' => 'Non-cancelled appointments starting in range.'],
        'appointments.done' => ['kind' => 'count', 'vat' => 'none', 'grant' => null, 'sources' => 'appointments', 'formula' => 'Those with status done.'],
        'appointments.no_show' => ['kind' => 'count', 'vat' => 'none', 'grant' => null, 'sources' => 'appointments', 'formula' => 'Those flagged no_show.'],
        'appointments.cancelled' => ['kind' => 'count', 'vat' => 'none', 'grant' => null, 'sources' => 'appointments', 'formula' => 'Cancelled appointments starting in range.'],

        'inventory.items_in_stock' => ['kind' => 'count', 'vat' => 'none', 'grant' => null, 'sources' => 'stock_balances', 'formula' => 'Item/location balances with on_hand > 0.'],
        'inventory.below_reorder' => ['kind' => 'count', 'vat' => 'none', 'grant' => null, 'sources' => 'stock_balances, stock_items', 'formula' => 'Items whose available (on_hand − reserved, all in-scope locations) is below the reorder level.'],
        'inventory.valuation_minor' => ['kind' => 'money', 'vat' => 'net', 'grant' => 'P-82', 'sources' => 'stock_balances, purchase_order_lines', 'formula' => 'Σ on_hand × weighted average net cost per unit (accepted purchase lines, VAT excluded); items never purchased are unvalued.'],
        'inventory.unvalued_items' => ['kind' => 'count', 'vat' => 'none', 'grant' => 'P-82', 'sources' => 'stock_balances, purchase_order_lines', 'formula' => 'Stocked items with no accepted purchase cost.'],
        'inventory.received_milli' => ['kind' => 'quantity', 'vat' => 'none', 'grant' => null, 'sources' => 'stock_movements', 'formula' => 'Σ positive on_hand_delta in range.'],
        'inventory.issued_milli' => ['kind' => 'quantity', 'vat' => 'none', 'grant' => null, 'sources' => 'stock_movements', 'formula' => 'Σ |negative on_hand_delta| in range.'],

        'procurement.orders_placed' => ['kind' => 'count', 'vat' => 'none', 'grant' => null, 'sources' => 'purchase_orders', 'formula' => 'Purchase orders ordered in range.'],
        'procurement.ordered_net_minor' => ['kind' => 'money', 'vat' => 'net', 'grant' => 'P-82', 'sources' => 'purchase_orders', 'formula' => 'Σ net_minor of POs ordered in range, not cancelled before ordering.'],
        'procurement.ordered_gross_minor' => ['kind' => 'money', 'vat' => 'gross', 'grant' => 'P-82', 'sources' => 'purchase_orders', 'formula' => 'Σ gross_minor of the same set.'],
        'procurement.remaining_milli' => ['kind' => 'quantity', 'vat' => 'none', 'grant' => null, 'sources' => 'purchase_order_lines', 'formula' => 'Σ max(quantity − accepted, 0) on ordered / partially received POs (rejected never satisfies).'],
        'procurement.late_orders' => ['kind' => 'count', 'vat' => 'none', 'grant' => null, 'sources' => 'purchase_orders', 'formula' => 'Ordered / partially received POs with expected_on before today.'],

        'returns.requested' => ['kind' => 'count', 'vat' => 'none', 'grant' => null, 'sources' => 'order_returns', 'formula' => 'Returns created in range.'],
        'returns.accepted_milli' => ['kind' => 'quantity', 'vat' => 'none', 'grant' => null, 'sources' => 'order_return_lines', 'formula' => 'Σ accepted quantity of returns created in range.'],
        'returns.refund_value_minor' => ['kind' => 'money', 'vat' => 'gross', 'grant' => 'P-37', 'sources' => 'order_returns', 'formula' => 'Σ settlement_minor of returns created in range.'],
        'cancellations.confirmed' => ['kind' => 'count', 'vat' => 'none', 'grant' => null, 'sources' => 'order_cancellations', 'formula' => 'Cancellations decided (confirmed) in range.'],
        'cancellations.partial' => ['kind' => 'count', 'vat' => 'none', 'grant' => null, 'sources' => 'order_cancellations', 'formula' => 'Of those, scope partial.'],
        'cancellations.retained_minor' => ['kind' => 'money', 'vat' => 'gross', 'grant' => 'P-37', 'sources' => 'order_cancellations', 'formula' => 'Σ retained_minor of those cancellations.'],
        'cancellations.open_settlement_items' => ['kind' => 'count', 'vat' => 'none', 'grant' => 'P-37', 'sources' => 'settlement_items', 'formula' => 'Settlement items still open.'],

        'store.checkouts' => ['kind' => 'count', 'vat' => 'none', 'grant' => null, 'sources' => 'store_checkouts', 'formula' => 'Checkouts created in range.'],
        'store.paid' => ['kind' => 'count', 'vat' => 'none', 'grant' => null, 'sources' => 'store_checkouts', 'formula' => 'Checkouts paid in range.'],
        'store.paid_total_minor' => ['kind' => 'money', 'vat' => 'gross', 'grant' => 'P-37', 'sources' => 'store_checkouts', 'formula' => 'Σ total_minor of checkouts paid in range.'],
        'store.discount_minor' => ['kind' => 'money', 'vat' => 'gross', 'grant' => 'P-37', 'sources' => 'store_checkouts', 'formula' => 'Σ discount_minor of those checkouts.'],
        'store.conversion_percent' => ['kind' => 'percent', 'vat' => 'none', 'grant' => null, 'sources' => 'store_checkouts', 'formula' => 'paid ÷ created in range × 100, one decimal.'],
    ];

    public static function has(string $key): bool
    {
        return isset(self::DEFINITIONS[$key]);
    }

    /**
     * @return array{kind: string, vat: string, grant: string|null, sources: string, formula: string}
     */
    public static function get(string $key): array
    {
        return self::DEFINITIONS[$key] ?? throw new InvalidArgumentException("Metric {$key} is not defined.");
    }
}
