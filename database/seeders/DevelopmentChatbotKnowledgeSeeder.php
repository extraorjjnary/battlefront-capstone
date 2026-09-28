<?php

namespace Database\Seeders;

use App\Enums\ChatbotCategory;
use App\Models\Branch;
use App\Models\ChatbotKnowledge;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;

class DevelopmentChatbotKnowledgeSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! App::environment(['local', 'testing'])) {
            return;
        }

        $operationalBranch = Branch::query()->operational()->firstOrFail();
        $paymentVerificationQuestion = 'How is payment proof verified for each method?';
        $paymentVerificationAnswer = 'GCash and Maya require uploaded payment proof, which Battlefront staff review manually. Cash and Card at store are pickup payment methods and do not require an upload; staff confirm those payments manually. Check your order history for the latest payment status.';

        if (! ChatbotKnowledge::query()->where('question_pattern', $paymentVerificationQuestion)->exists()) {
            ChatbotKnowledge::query()
                ->where('category', ChatbotCategory::Faq)
                ->where('question_pattern', 'How is GCash payment verified?')
                ->where('response_template', 'GCash payment proof is reviewed manually by Battlefront staff. Check your order history for the updated payment status.')
                ->where('priority', 60)
                ->where('is_active', true)
                ->update([
                    'question_pattern' => $paymentVerificationQuestion,
                    'response_template' => $paymentVerificationAnswer,
                ]);
        }

        /** @var list<array{category: ChatbotCategory, question_pattern: string, response_template: string, priority: int}> $knowledgeEntries */
        $knowledgeEntries = [
            [
                'category' => ChatbotCategory::Store,
                'question_pattern' => 'What time does the Sagay store open and close?',
                'response_template' => "The Sagay City branch's confirmed operating hours are {$operationalBranch->operating_hours}.",
                'priority' => 100,
            ],
            [
                'category' => ChatbotCategory::Store,
                'question_pattern' => 'Where is the Sagay City branch located?',
                'response_template' => "The Sagay City branch is located at {$operationalBranch->address}.",
                'priority' => 100,
            ],
            [
                'category' => ChatbotCategory::Store,
                'question_pattern' => 'How can I contact the Sagay store?',
                'response_template' => "You can contact the Sagay City branch at {$operationalBranch->contact_number} or {$operationalBranch->email}.",
                'priority' => 100,
            ],
            [
                'category' => ChatbotCategory::Product,
                'question_pattern' => 'Is this product currently available?',
                'response_template' => "Product availability changes with current inventory. Please provide the product name so availability can be checked against Battlefront's current catalog and Sagay inventory.",
                'priority' => 80,
            ],
            [
                'category' => ChatbotCategory::Product,
                'question_pattern' => "Do you have the product I saw on Battlefront's Facebook page?",
                'response_template' => "Please provide the product name or Facebook post details. Availability and current pricing must be checked against Battlefront's catalog and Sagay inventory.",
                'priority' => 80,
            ],
            [
                'category' => ChatbotCategory::Product,
                'question_pattern' => 'Do you have Piso WiFi products available?',
                'response_template' => "Piso WiFi product availability must be checked against Battlefront's current catalog and Sagay inventory. You may also contact the Sagay City branch.",
                'priority' => 80,
            ],
            [
                'category' => ChatbotCategory::Order,
                'question_pattern' => 'How can I check my order status?',
                'response_template' => 'Sign in and open your order history to view the latest status for your order.',
                'priority' => 70,
            ],
            [
                'category' => ChatbotCategory::Order,
                'question_pattern' => 'Can I choose pickup or delivery?',
                'response_template' => 'Battlefront supports pickup and delivery. Choose an available fulfillment option during checkout; a delivery address is required for delivery.',
                'priority' => 70,
            ],
            [
                'category' => ChatbotCategory::Order,
                'question_pattern' => 'What payment methods can I use?',
                'response_template' => 'Pickup orders support Cash, Card at store, GCash, and Maya. Delivery orders support GCash and Maya.',
                'priority' => 70,
            ],
            [
                'category' => ChatbotCategory::Faq,
                'question_pattern' => $paymentVerificationQuestion,
                'response_template' => $paymentVerificationAnswer,
                'priority' => 60,
            ],
            [
                'category' => ChatbotCategory::Faq,
                'question_pattern' => 'Do you offer real-time delivery tracking?',
                'response_template' => 'Real-time delivery tracking is not available. Sign in and check your order history for the latest order status.',
                'priority' => 60,
            ],
        ];

        foreach ($knowledgeEntries as $knowledgeEntry) {
            ChatbotKnowledge::query()->firstOrCreate(
                ['question_pattern' => $knowledgeEntry['question_pattern']],
                [
                    'category' => $knowledgeEntry['category'],
                    'response_template' => $knowledgeEntry['response_template'],
                    'priority' => $knowledgeEntry['priority'],
                    'is_active' => true,
                ],
            );
        }
    }
}
