<?php

namespace App\Models;

use App\Enums\ChatbotCategory;
use Database\Factories\ChatbotKnowledgeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property ChatbotCategory $category
 * @property string $question_pattern
 * @property string $response_template
 * @property int $priority
 * @property bool $is_active
 */
#[Fillable([
    'category',
    'question_pattern',
    'response_template',
    'priority',
    'is_active',
])]
class ChatbotKnowledge extends Model
{
    /** @use HasFactory<ChatbotKnowledgeFactory> */
    use HasFactory;

    /** @var string */
    protected $table = 'chatbot_knowledge';

    /** @var bool */
    public $timestamps = false;

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * Scope a query to active chatbot knowledge.
     *
     * @param  Builder<ChatbotKnowledge>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'category' => ChatbotCategory::class,
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
