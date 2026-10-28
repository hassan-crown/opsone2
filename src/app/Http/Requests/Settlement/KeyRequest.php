<?php

namespace App\Http\Requests\Settlement;

use App\Http\Requests\Finance\FinanceWriteRequest;

/**
 * Accepting an external document: only the envelope.
 */
class KeyRequest extends FinanceWriteRequest {}
