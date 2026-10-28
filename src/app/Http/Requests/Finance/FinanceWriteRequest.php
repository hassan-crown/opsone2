<?php

namespace App\Http\Requests\Finance;

use App\Http\Requests\Agreements\AgreementWriteRequest;

/**
 * Base of the finance writes (docs/p6-finance-api.md): the same envelope as the agreement writes — an
 * `idempotency_key` (field or `Idempotency-Key` header) on every money write and the `lock_version` the screen read.
 */
class FinanceWriteRequest extends AgreementWriteRequest {}
