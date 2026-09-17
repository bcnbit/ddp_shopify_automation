<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SyncOperation;
use App\Enums\SyncStatus;
use App\Models\Product;
use App\Models\SyncAttempt;
use App\Support\Products\IdempotencyKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SyncAttempt>
 */
class SyncAttemptFactory extends Factory
{
    protected $model = SyncAttempt::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $product = Product::factory();

        return [
            'product_id' => $product,
            'operation' => SyncOperation::CreateProduct,
            'idempotency_key' => IdempotencyKey::make(1, 1, SyncOperation::CreateProduct),
            'attempt_number' => 1,
            'status' => SyncStatus::Pending,
            'request_payload' => null,
            'response_payload' => null,
            'error_message' => null,
            'error_code' => null,
            'is_retryable' => false,
            'support_reference' => null,
            'attempted_by' => null,
            'started_at' => null,
            'finished_at' => null,
        ];
    }

    public function succeeded(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SyncStatus::Succeeded,
            'response_payload' => ['product' => ['id' => 'gid://shopify/Product/1']],
            'started_at' => now()->subSeconds(5),
            'finished_at' => now(),
        ]);
    }

    public function failed(bool $retryable = true): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SyncStatus::Failed,
            'error_message' => 'La API de Shopify ha devuelto un error temporal.',
            'error_code' => 'THROTTLED',
            'is_retryable' => $retryable,
            'support_reference' => 'SYNC-00000042',
            'started_at' => now()->subSeconds(5),
            'finished_at' => now(),
        ]);
    }

    public function forProduct(Product $product): static
    {
        return $this->state(fn (array $attributes): array => [
            'product_id' => $product->getKey(),
        ]);
    }
}
