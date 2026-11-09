<?php

namespace App\Ai;

/**
 * How one gateway call ended (ai_usage.outcome). Only `ok` charges credits; every other outcome sends the feature
 * back to its manual path («الاقتراح غير متاح الآن»).
 */
enum AiOutcome: string
{
    case Pending = 'pending';
    case Ok = 'ok';
    /** The model declined (stop_reason refusal). */
    case Refused = 'refused';
    /** Output cut off (max_tokens), not JSON, failing the schema, or carrying a fact with no source. */
    case Invalid = 'invalid';
    case Timeout = 'timeout';
    /** Monthly credits used up, or the daily fair-use cap of an included task reached. */
    case NoCredit = 'no_credit';
    /** Provider error, or the circuit breaker is open. */
    case Unavailable = 'unavailable';
    /** The provider rejected the request (key, permission, bad request): a configuration problem, not an outage. */
    case Rejected = 'rejected';
    /** The `log` driver: recorded, nothing left the server. */
    case NotSent = 'not_sent';
    /** The driver refuses to run (anthropic without its explicit switch or key). */
    case DriverDisabled = 'driver_disabled';
    /** Feature switched off by the facility, or not in the license. Never recorded in ai_usage. */
    case FeatureOff = 'feature_off';

    public function isOk(): bool
    {
        return $this === self::Ok;
    }
}
