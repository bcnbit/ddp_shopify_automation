<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ActivityEvent;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityLog>
 */
class ActivityLogFactory extends Factory
{
    protected $model = ActivityLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'actor_email' => fake()->safeEmail(),
            'event' => ActivityEvent::Updated,
            'subject_type' => null,
            'subject_id' => null,
            'description' => fake()->sentence(),
            'properties' => null,
            'changes' => null,
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'request_id' => fake()->uuid(),
        ];
    }
}
