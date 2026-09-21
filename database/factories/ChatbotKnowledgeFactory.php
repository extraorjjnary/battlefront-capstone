<?php

namespace Database\Factories;

use App\Enums\ChatbotCategory;
use App\Models\ChatbotKnowledge;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatbotKnowledge>
 */
class ChatbotKnowledgeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category' => fake()->randomElement(ChatbotCategory::cases()),
            'question_pattern' => fake()->unique()->sentence(4),
            'response_template' => fake()->paragraph(),
            'priority' => fake()->numberBetween(0, 100),
        ];
    }

    /**
     * Indicate that the chatbot knowledge is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
