<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        /** @var list<array{string, string, string, int}> $presets */
        $presets = [
            ['store', 'What time does the Sagay store open and close?', 'The Sagay City branch\'s confirmed operating hours are 8:00 AM–6:00 PM.', 100],
            ['store', 'Where is the Sagay City branch located?', 'The Sagay City branch is located at A, E Marañon St., Brgy. Poblacion II, Sagay City, Negros Occidental (beside LBC Sagay City), Sagay, Philippines 6122.', 100],
            ['store', 'How can I contact the Sagay store?', 'You can contact the Sagay City branch at 0938 647 6046 or battlefrontcomputertrading@gmail.com.', 100],
            ['product', 'Is this product currently available?', 'Product availability changes with current inventory. Please provide the product name so availability can be checked against Battlefront\'s current catalog and Sagay inventory.', 80],
            ['product', 'Do you have the product I saw on Battlefront\'s Facebook page?', 'Please provide the product name or Facebook post details. Availability and current pricing must be checked against Battlefront\'s catalog and Sagay inventory.', 80],
            ['product', 'Do you have Piso WiFi products available?', 'Piso WiFi product availability must be checked against Battlefront\'s current catalog and Sagay inventory. You may also contact the Sagay City branch.', 80],
            ['order', 'Can I choose pickup or delivery?', 'Battlefront supports pickup and delivery. Choose an available fulfillment option during checkout; a delivery address is required for delivery.', 70],
            ['order', 'What payment methods can I use?', 'Pickup orders support Cash, Card at store, GCash, and Maya. Delivery orders support GCash and Maya.', 70],
            ['faq', 'How is payment proof verified for each method?', 'GCash and Maya require uploaded payment proof, which Battlefront staff review manually. Cash and Card at store are pickup payment methods and do not require an upload; staff confirm those payments manually. Check your order history for the latest payment status.', 60],
            ['faq', 'How is GCash payment verified?', 'GCash payment proof is reviewed manually by Battlefront staff. Check your order history for the updated payment status.', 60],
        ];

        DB::transaction(function () use ($presets): void {
            foreach ($presets as [$category, $question, $answer, $priority]) {
                $records = DB::table('chatbot_knowledge')
                    ->where('is_active', false)
                    ->where('question_pattern', $question)
                    ->lockForUpdate()
                    ->get();

                foreach ($records as $record) {
                    if ($record->category !== $category || $record->question_pattern !== $question
                        || $record->response_template !== $answer || (int) $record->priority !== $priority) {
                        continue;
                    }

                    DB::table('chatbot_knowledge')->where('id', $record->id)->delete();
                }
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Retired preset cleanup is forward-only: deleted record IDs and prior state cannot be reconstructed.');
    }
};
