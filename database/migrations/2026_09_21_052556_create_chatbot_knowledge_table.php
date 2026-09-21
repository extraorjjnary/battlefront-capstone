<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('chatbot_knowledge', function (Blueprint $table) {
            $table->id();
            $table->string('category', 16);
            $table->string('question_pattern');
            $table->text('response_template');
            $table->integer('priority');
            $table->boolean('is_active')->default(true);
        });

        $this->addCategoryConstraint();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chatbot_knowledge');
    }

    /**
     * Enforce the manuscript-approved chatbot categories.
     */
    private function addCategoryConstraint(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER chatbot_knowledge_category_insert_check
                BEFORE INSERT ON chatbot_knowledge
                WHEN NEW.category NOT IN ('product', 'order', 'store', 'faq')
                BEGIN
                    SELECT RAISE(ABORT, 'Invalid chatbot knowledge category.');
                END
            SQL);

            DB::statement(<<<'SQL'
                CREATE TRIGGER chatbot_knowledge_category_update_check
                BEFORE UPDATE OF category ON chatbot_knowledge
                WHEN NEW.category NOT IN ('product', 'order', 'store', 'faq')
                BEGIN
                    SELECT RAISE(ABORT, 'Invalid chatbot knowledge category.');
                END
            SQL);

            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE chatbot_knowledge
                ADD CONSTRAINT chatbot_knowledge_category_valid
                CHECK (category IN ('product', 'order', 'store', 'faq'))
        SQL);
    }
};
