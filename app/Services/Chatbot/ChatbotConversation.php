<?php

namespace App\Services\Chatbot;

use App\Actions\Chatbot\CategorizeChatbotQuery;
use App\Actions\Chatbot\Context\ResolveProductContext;
use App\Actions\Chatbot\RouteChatbotQuery;
use App\Enums\ChatbotQueryCategory;
use App\Models\Branch;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Str;

class ChatbotConversation
{
    public function __construct(
        private ChatbotOrchestrationService $chatbot,
        private ChatbotConversationToken $tokens,
        private RouteChatbotQuery $router,
        private CategorizeChatbotQuery $categorizer,
        private ResolveProductContext $products,
    ) {}

    /** @return array{message: string, source: string, context_token: string|null} */
    public function respond(string $message, ?User $customer, ?string $token, string $sessionId): array
    {
        $subject = $sessionId.':'.($customer?->getKey() ?? 'guest');
        $state = $this->tokens->decode($token, $subject);
        $message = Str::squish($message);

        if ($this->chatbot->containsSensitiveInput($message) || $this->categorizer->isBlocked($message)) {
            $result = $this->chatbot->respond($message, $customer);

            return ['message' => $result['message'], 'source' => $result['source'], 'context_token' => null];
        }

        $selected = $this->selectedTopic($message);
        if ($selected !== null && isset($state['pending'][$selected])) {
            $message = $state['pending'][$selected];
        }
        unset($state['pending']);

        $routing = $this->router->execute($message);
        if ($routing['choices'] !== []) {
            $state['pending'] = $routing['choices'];

            return $this->fallback($this->router->clarification($routing['choices']), $state, $subject);
        }

        $productId = null;
        $category = $routing['category'];
        $isFollowUp = preg_match('/\b(?:it|its|that|this|there|earlier|previous)\b|^(?:how much|and the)\b/iu', $message) === 1;

        if ($isFollowUp && $this->hasExplicitEntity($message, $category)) {
            $isFollowUp = false;
        }

        if (($state['active'] ?? null) === 'store'
            && in_array($category, [ChatbotQueryCategory::Unsupported, ChatbotQueryCategory::Store], true)
            && ! $this->categorizer->requiresLiveFacts($message)
            && preg_match('/^(?:what|how) about\b/iu', $message) === 1) {
            $namedCity = Branch::query()->pluck('city')->first(
                fn (string $city): bool => str_contains(Str::lower($message), Str::lower(Str::before($city, ' City'))),
            );
            if ($namedCity !== null) {
                $message = ($state['store_intent'] ?? 'location').' of '.$namedCity.' branch';
                $category = ChatbotQueryCategory::Store;
                $isFollowUp = false;
            }
        }

        if ($isFollowUp) {
            $target = $category === ChatbotQueryCategory::Unsupported
                ? ($state['active'] ?? null)
                : $category->value;
            if (preg_match('/\b(?:earlier|previous) (?:product|order|branch|store)\b/iu', $message) === 1) {
                $target = preg_match('/\border\b/iu', $message) === 1 ? 'order'
                    : (preg_match('/\bproduct\b/iu', $message) === 1 ? 'product' : 'store');
            } elseif ($target !== ($state['active'] ?? null)) {
                return $this->fallback('Which product, branch, or order do you mean? Please include its name or order reference.', $state, $subject);
            }

            $references = $state['references'][$target ?? ''] ?? [];
            if (count($references) !== 1) {
                return $this->fallback('Which product, branch, or order do you mean? Please include its name or order reference.', $state, $subject);
            }

            if ($target === 'product') {
                $productId = $references[0];
                $message = 'Product inquiry: '.$message;
            } elseif ($target === 'store') {
                $message = rtrim($message, '.?!').' at '.$references[0].' branch';
            } elseif ($target === 'order') {
                $message = rtrim($message, '.?!').' order '.$references[0];
            }
        } elseif ($category === ChatbotQueryCategory::Order
            && preg_match('/\bbf[\s\W_]*\d+\b/iu', $message) !== 1
            && ($state['active'] ?? null) === 'order'
            && count($state['references']['order'] ?? []) === 1) {
            $message = rtrim($message, '.?!').' '.$state['references']['order'][0];
        }

        $resolved = false;
        $result = $this->chatbot->respond($message, $customer, function (ChatbotQueryCategory $category, array $context) use (&$state, &$resolved, $message): void {
            $resolved = true;
            $state['active'] = $category->value;
            if ($category === ChatbotQueryCategory::Product) {
                $state['references']['product'] = Product::query()->customerEligible()
                    ->whereIn('name', array_column($context['products'], 'name'))
                    ->limit(6)->pluck('id')->all();
            } elseif ($category === ChatbotQueryCategory::Store) {
                $state['references']['store'] = array_slice(array_column($context['branches'], 'city'), 0, 5);
                $state['store_intent'] = match (true) {
                    preg_match('/\b(?:hours|open|close|time)\b/iu', $message) === 1 => 'opening hours',
                    preg_match('/\b(?:contact|phone|email|telephone)\b/iu', $message) === 1 => 'contact information',
                    default => 'location',
                };
            } elseif ($category === ChatbotQueryCategory::Order) {
                $state['references']['order'] = array_column($context['orders'], 'reference');
            }
        }, $productId);

        if (! $resolved) {
            $state['active'] = $this->categorizer->execute($message)->value;
            unset($state['references'][$state['active']]);
        }

        if ($result['source'] === 'gemini' && $state['active'] === 'product'
            && preg_match('/\b(?:stock|available|availability)\b/iu', $message) === 1) {
            $result['message'] .= ' Live inventory information covers the Sagay branch only.';
        }

        return [
            'message' => $result['message'],
            'source' => $result['source'],
            'context_token' => $this->tokens->encode($state, $subject),
        ];
    }

    private function selectedTopic(string $message): ?string
    {
        return match (Str::of($message)->lower()->trim()->rtrim('.?!')->toString()) {
            'product', 'products', 'price', 'pricing', 'product pricing', 'product information' => 'product',
            'order', 'my order', 'order status' => 'order',
            'store', 'branch', 'store information' => 'store',
            'faq', 'payment', 'delivery', 'payment or delivery guidance', 'delivery information' => 'faq',
            default => null,
        };
    }

    private function hasExplicitEntity(string $message, ChatbotQueryCategory $category): bool
    {
        if ($category === ChatbotQueryCategory::Order) {
            return preg_match('/\bbf[\s\W_]*\d+\b/iu', $message) === 1;
        }

        $normalized = Str::of($message)->lower()->replaceMatches('/[^\p{L}\p{N}\s]+/u', ' ')->squish()->toString();
        if ($category === ChatbotQueryCategory::Store) {
            return Branch::query()->pluck('city')->contains(function (string $city) use ($normalized): bool {
                $city = Str::lower(Str::before($city, ' City'));

                return str_contains(" {$normalized} ", " {$city} ");
            });
        }

        if ($category === ChatbotQueryCategory::Product) {
            return $this->products->namesProduct($message);
        }

        return $category === ChatbotQueryCategory::Faq
            && preg_match('/\b(?:it|its|that|this|there|earlier|previous)\b/iu', $message) !== 1;
    }

    /** @param array<string, mixed> $state
     * @return array{message: string, source: string, context_token: string}
     */
    private function fallback(string $message, array $state, string $subject): array
    {
        return ['message' => $message, 'source' => 'fallback', 'context_token' => $this->tokens->encode($state, $subject)];
    }
}
