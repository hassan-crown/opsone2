<?php

namespace App\Ai\Sales;

use App\Ai\Contracts\OutputCheck;
use App\Ai\Inbox\FinancialCheck;

/**
 * The inbox FinancialCheck applied to the drafted reply only. The extracted fields legitimately echo what the customer
 * typed (a budget, a preferred day) and staff read them before accepting; the reply is what goes to the customer, and
 * it may state no amount, percentage or date the facility did not give (the slots are added by the server).
 */
final class ReplyFinancialCheck implements OutputCheck
{
    public function check(array $output, array $facts): array
    {
        return (new FinancialCheck)->check(['reply' => (string) ($output['reply'] ?? '')], $facts);
    }
}
