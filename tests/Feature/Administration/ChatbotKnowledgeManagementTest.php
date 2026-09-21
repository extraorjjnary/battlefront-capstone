<?php

use App\Enums\ChatbotCategory;
use App\Models\ChatbotKnowledge;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

dataset('chatbot knowledge administration routes', [
    'index' => ['get', 'administration.chatbot-knowledge.index', false, []],
    'create' => ['get', 'administration.chatbot-knowledge.create', false, []],
    'store' => ['post', 'administration.chatbot-knowledge.store', false, [
        'category' => 'faq',
        'question_pattern' => 'What are your store hours?',
        'response_template' => 'Battlefront is open from 8:00 AM to 6:00 PM.',
        'priority' => 10,
    ]],
    'show' => ['get', 'administration.chatbot-knowledge.show', true, []],
    'edit' => ['get', 'administration.chatbot-knowledge.edit', true, []],
    'update' => ['put', 'administration.chatbot-knowledge.update', true, [
        'category' => 'store',
        'question_pattern' => 'Where is Battlefront located?',
        'response_template' => 'View the approved branch information.',
        'priority' => 5,
    ]],
    'activation' => ['patch', 'administration.chatbot-knowledge.activation.update', true, [
        'is_active' => false,
    ]],
]);

test('guests are redirected from every chatbot knowledge administration route', function (
    string $method,
    string $routeName,
    bool $requiresRecord,
    array $payload,
) {
    $knowledge = $requiresRecord ? ChatbotKnowledge::factory()->create() : null;
    $parameters = $knowledge === null ? [] : [$knowledge];

    $this->{$method}(route($routeName, $parameters), $payload)
        ->assertRedirect(route('login'));
})->with('chatbot knowledge administration routes');

test('customers are forbidden from every chatbot knowledge administration route', function (
    string $method,
    string $routeName,
    bool $requiresRecord,
    array $payload,
) {
    $customer = User::factory()->customer()->create();
    $knowledge = $requiresRecord ? ChatbotKnowledge::factory()->create() : null;
    $parameters = $knowledge === null ? [] : [$knowledge];

    $this->actingAs($customer)
        ->{$method}(route($routeName, $parameters), $payload)
        ->assertForbidden();
})->with('chatbot knowledge administration routes');

test('chatbot knowledge has no physical deletion route', function () {
    expect(Route::has('administration.chatbot-knowledge.destroy'))->toBeFalse();
});

test('administrators can list active and inactive chatbot knowledge in stable order', function () {
    $administrator = User::factory()->administrator()->create();
    $first = ChatbotKnowledge::factory()->inactive()->create([
        'question_pattern' => 'First question',
    ]);
    $second = ChatbotKnowledge::factory()->create([
        'question_pattern' => 'Second question',
    ]);

    $response = $this->actingAs($administrator)
        ->get(route('administration.chatbot-knowledge.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Administration/ChatbotKnowledge/Index')
        ->has('knowledge.data', 2)
        ->where('knowledge.data.0.id', $first->id)
        ->where('knowledge.data.0.is_active', false)
        ->where('knowledge.data.1.id', $second->id)
        ->where('knowledge.data.1.is_active', true));
});

test('chatbot knowledge is paginated without assigning meaning to priority', function () {
    $administrator = User::factory()->administrator()->create();
    ChatbotKnowledge::factory()->count(16)->create();

    $response = $this->actingAs($administrator)
        ->get(route('administration.chatbot-knowledge.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->has('knowledge.data', 15)
        ->where('knowledge.total', 16)
        ->where('knowledge.last_page', 2));
});

test('administrators can open create view and edit pages', function () {
    $administrator = User::factory()->administrator()->create();
    $knowledge = ChatbotKnowledge::factory()->inactive()->create([
        'category' => ChatbotCategory::Store,
        'question_pattern' => 'Where is the Sagay branch?',
        'response_template' => 'The branch is in Sagay City.',
        'priority' => 12,
    ]);

    $this->actingAs($administrator)
        ->get(route('administration.chatbot-knowledge.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Administration/ChatbotKnowledge/Create')
            ->has('categories', 4));
    $this->actingAs($administrator)
        ->get(route('administration.chatbot-knowledge.show', $knowledge))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Administration/ChatbotKnowledge/Show')
            ->where('knowledge.category', 'store')
            ->where('knowledge.category_label', 'Store')
            ->where('knowledge.question_pattern', 'Where is the Sagay branch?')
            ->where('knowledge.is_active', false));
    $this->actingAs($administrator)
        ->get(route('administration.chatbot-knowledge.edit', $knowledge))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Administration/ChatbotKnowledge/Edit')
            ->where('knowledge.response_template', 'The branch is in Sagay City.')
            ->where('knowledge.priority', 12)
            ->has('categories', 4));
});

test('administrators can create chatbot knowledge without changing the active default', function () {
    $administrator = User::factory()->administrator()->create();

    $response = $this->actingAs($administrator)
        ->post(route('administration.chatbot-knowledge.store'), [
            'category' => 'faq',
            'question_pattern' => 'What are your store hours?',
            'response_template' => 'Battlefront is open from 8:00 AM to 6:00 PM.',
            'priority' => 10,
            'is_active' => false,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('administration.chatbot-knowledge.index'));
    $this->assertDatabaseHas('chatbot_knowledge', [
        'category' => 'faq',
        'question_pattern' => 'What are your store hours?',
        'response_template' => 'Battlefront is open from 8:00 AM to 6:00 PM.',
        'priority' => 10,
        'is_active' => true,
    ]);
});

test('chatbot knowledge inputs are validated', function (array $payload, array $errors) {
    $administrator = User::factory()->administrator()->create();
    $validPayload = [
        'category' => 'product',
        'question_pattern' => 'Is this product available?',
        'response_template' => 'Check the current product information.',
        'priority' => 1,
    ];

    $response = $this->actingAs($administrator)
        ->from(route('administration.chatbot-knowledge.create'))
        ->post(
            route('administration.chatbot-knowledge.store'),
            [...$validPayload, ...$payload],
        );

    $response
        ->assertRedirect(route('administration.chatbot-knowledge.create'))
        ->assertSessionHasErrors($errors);
    $this->assertDatabaseCount('chatbot_knowledge', 0);
})->with([
    'required fields' => [
        [
            'category' => null,
            'question_pattern' => null,
            'response_template' => null,
            'priority' => null,
        ],
        [
            'category' => 'Select a chatbot category.',
            'question_pattern' => 'Enter a question pattern.',
            'response_template' => 'Enter a response template.',
            'priority' => 'Enter a priority.',
        ],
    ],
    'invalid category and priority' => [
        ['category' => 'support', 'priority' => 'high'],
        [
            'category' => 'Select a valid chatbot category.',
            'priority' => 'The priority must be a whole number.',
        ],
    ],
    'oversized question pattern and priority' => [
        ['question_pattern' => Str::repeat('x', 256), 'priority' => 2147483648],
        [
            'question_pattern' => 'The question pattern must not exceed 255 characters.',
            'priority' => 'The priority is outside the supported range.',
        ],
    ],
]);

test('administrators can edit content without changing activation', function () {
    $administrator = User::factory()->administrator()->create();
    $knowledge = ChatbotKnowledge::factory()->inactive()->create();

    $response = $this->actingAs($administrator)
        ->put(route('administration.chatbot-knowledge.update', $knowledge), [
            'category' => 'order',
            'question_pattern' => 'Where is my order?',
            'response_template' => 'Review your current order details.',
            'priority' => -5,
            'is_active' => true,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('administration.chatbot-knowledge.show', $knowledge));
    $this->assertDatabaseHas('chatbot_knowledge', [
        'id' => $knowledge->id,
        'category' => 'order',
        'question_pattern' => 'Where is my order?',
        'response_template' => 'Review your current order details.',
        'priority' => -5,
        'is_active' => false,
    ]);
});

test('administrators can deactivate and reactivate knowledge without deleting it', function () {
    $administrator = User::factory()->administrator()->create();
    $knowledge = ChatbotKnowledge::factory()->create();

    $this->actingAs($administrator)
        ->from(route('administration.chatbot-knowledge.show', $knowledge))
        ->patch(route('administration.chatbot-knowledge.activation.update', $knowledge), [
            'is_active' => false,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('administration.chatbot-knowledge.show', $knowledge));

    $this->assertModelExists($knowledge);
    expect($knowledge->refresh()->is_active)->toBeFalse();

    $this->actingAs($administrator)
        ->patch(route('administration.chatbot-knowledge.activation.update', $knowledge), [
            'is_active' => true,
        ])
        ->assertSessionHasNoErrors();

    $this->assertModelExists($knowledge);
    expect($knowledge->refresh()->is_active)->toBeTrue();
});

test('chatbot knowledge activation requires an explicit boolean state', function () {
    $administrator = User::factory()->administrator()->create();
    $knowledge = ChatbotKnowledge::factory()->create();

    $this->actingAs($administrator)
        ->from(route('administration.chatbot-knowledge.show', $knowledge))
        ->patch(route('administration.chatbot-knowledge.activation.update', $knowledge))
        ->assertRedirect(route('administration.chatbot-knowledge.show', $knowledge))
        ->assertSessionHasErrors('is_active');

    expect($knowledge->refresh()->is_active)->toBeTrue();
});
