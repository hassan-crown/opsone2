<?php

namespace App\Ai\Contracts;

use App\Ai\AiRequest;
use App\Ai\AiResponse;
use App\Ai\Exceptions\AiDriverDisabled;
use App\Ai\Exceptions\AiProviderTimeout;
use App\Ai\Exceptions\AiProviderUnavailable;

/**
 * The single seam to a model provider (ADR-0050 §3). Chosen by AI_DRIVER: log | fake | anthropic. Another provider
 * (Bedrock, Vertex, …) is a new driver; features do not change. Batch submission is added with the first batch task
 * (plan AI-7b / AI-8); until then a batch task runs through complete().
 */
interface AiProvider
{
    public function name(): string;

    /**
     * @throws AiDriverDisabled when the driver may not send (missing explicit switch or key)
     * @throws AiProviderTimeout
     * @throws AiProviderUnavailable on any other provider or transport failure
     */
    public function complete(AiRequest $request): AiResponse;
}
