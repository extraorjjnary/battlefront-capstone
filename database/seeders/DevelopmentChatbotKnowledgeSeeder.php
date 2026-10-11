<?php

namespace Database\Seeders;

use App\Enums\ChatbotCategory;
use App\Models\ChatbotKnowledge;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;

class DevelopmentChatbotKnowledgeSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        if (! App::environment(['local', 'testing'])) {
            return;
        }

        /** @var array<string, string> $faqs */
        $faqs = [
            'How can I check my order status?' => 'Sign in and open your order history to view the latest status for your order.',
            'Do you offer real-time delivery tracking?' => 'Real-time delivery tracking is not available. Sign in and check your order history for the latest order status.',
            'How do I search for products?' => 'Open the product catalog and use search. You can narrow results by category, brand, or tag.',
            'How can I view a product\'s details?' => 'Open a product from the catalog to view its description, listed price, and availability.',
        ];

        foreach ($faqs as $question => $answer) {
            ChatbotKnowledge::query()->firstOrCreate(
                ['question_pattern' => $question],
                ['category' => ChatbotCategory::Faq, 'response_template' => $answer, 'priority' => 0, 'is_active' => true],
            );
        }
    }
}
