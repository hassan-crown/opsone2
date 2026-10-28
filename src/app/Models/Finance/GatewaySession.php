<?php

namespace App\Models\Finance;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * The provider object behind a pending online payment (a Moyasar invoice): how a provider notification that names
 * only its own ids finds our payment.
 *
 * @property int $id
 * @property int $payment_id
 * @property string $gateway
 * @property string $provider_ref
 * @property string $checkout_url
 * @property CarbonInterface|null $expires_at
 */
class GatewaySession extends Model
{
    protected $table = 'payment_gateway_sessions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }
}
