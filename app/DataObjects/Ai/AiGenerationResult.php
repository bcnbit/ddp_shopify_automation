<?php

declare(strict_types=1);

namespace App\DataObjects\Ai;

use App\Support\Ai\ContentProposal;

/**
 * Resultado de una generación (RFC-0003).
 *
 * Incluye la propuesta validada y los datos de coste para poder auditar el
 * gasto: modelo, tokens y latencia por ejecución.
 */
final readonly class AiGenerationResult
{
    public function __construct(
        public ContentProposal $proposal,
        public string $model,
        public ?string $promptVersion = null,
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public ?float $costEstimate = null,
        public ?int $latencyMs = null,
        public ?string $requestId = null,
    ) {}

    public function totalTokens(): ?int
    {
        if ($this->inputTokens === null && $this->outputTokens === null) {
            return null;
        }

        return (int) $this->inputTokens + (int) $this->outputTokens;
    }

    /**
     * @return array<string, mixed>
     */
    public function toAuditArray(): array
    {
        return [
            'model' => $this->model,
            'prompt_version' => $this->promptVersion,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'total_tokens' => $this->totalTokens(),
            'cost_estimate' => $this->costEstimate,
            'latency_ms' => $this->latencyMs,
            'request_id' => $this->requestId,
        ];
    }
}
