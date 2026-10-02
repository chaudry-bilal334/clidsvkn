<?php

namespace Database\Factories;

use App\Models\AiContentDraft;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiContentDraft>
 */
class AiContentDraftFactory extends Factory
{
    protected $model = AiContentDraft::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'title' => $this->faker->sentence(5),
            'content_type' => $this->faker->randomElement(AiContentDraft::CONTENT_TYPES),
            'content' => $this->faker->paragraphs(2, true),
            'status' => AiContentDraft::STATUS_DRAFT,
            'created_by' => User::factory(),
        ];
    }

    public function pendingReview(): static
    {
        return $this->state(fn () => [
            'status' => AiContentDraft::STATUS_PENDING_REVIEW,
            'submitted_by' => User::factory(),
            'submitted_at' => now(),
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => AiContentDraft::STATUS_APPROVED,
            'submitted_by' => User::factory(),
            'submitted_at' => now()->subHour(),
            'reviewed_by' => User::factory(),
            'reviewed_at' => now(),
        ]);
    }

    public function rejected(string $reason = 'Does not match brand guidelines.'): static
    {
        return $this->state(fn () => [
            'status' => AiContentDraft::STATUS_REJECTED,
            'submitted_by' => User::factory(),
            'submitted_at' => now()->subHour(),
            'reviewed_by' => User::factory(),
            'reviewed_at' => now(),
            'rejection_reason' => $reason,
        ]);
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'status' => AiContentDraft::STATUS_PUBLISHED,
            'submitted_by' => User::factory(),
            'submitted_at' => now()->subHours(2),
            'reviewed_by' => User::factory(),
            'reviewed_at' => now()->subHour(),
            'published_at' => now(),
        ]);
    }
}
