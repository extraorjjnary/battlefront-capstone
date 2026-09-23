<?php

namespace App\Services\Chatbot;

use App\Actions\Chatbot\CategorizeChatbotQuery;
use App\Actions\Chatbot\Context\ResolveFaqContext;
use App\Actions\Chatbot\Context\ResolveOrderContext;
use App\Actions\Chatbot\Context\ResolveProductContext;
use App\Actions\Chatbot\Context\ResolveStoreContext;
use App\Enums\ChatbotQueryCategory;
use App\Models\User;
use Illuminate\Support\Str;

class ChatbotOrchestrationService
{
    private const EMPTY_MESSAGE = 'Please enter a question about Battlefront products, orders, stores, payment methods, pickup, or delivery.';

    private const UNSUPPORTED_INQUIRY = 'I can only help with Battlefront products, your orders, store information, payment methods, pickup, and delivery.';

    private const NO_PRODUCT_MATCH = "I couldn't find a matching product in the current Battlefront catalog. Please try the product name, brand, category, or tag.";

    private const UNAUTHENTICATED_ORDER = 'Please sign in with a customer account to check order status.';

    private const ORDER_NOT_FOUND = "I couldn't find a matching order in your account.";

    private const STORE_INFORMATION_UNAVAILABLE = 'Store information is currently unavailable. Please try again later.';

    private const FAQ_INFORMATION_UNAVAILABLE = 'Approved information for that question is currently unavailable.';

    private const PROVIDER_TIMEOUT = 'The chatbot took too long to respond. Please try again.';

    private const PROVIDER_UNAVAILABLE = 'The chatbot is temporarily unavailable. Please try again later.';

    public function __construct(
        private CategorizeChatbotQuery $categorizeChatbotQuery,
        private ResolveProductContext $resolveProductContext,
        private ResolveOrderContext $resolveOrderContext,
        private ResolveStoreContext $resolveStoreContext,
        private ResolveFaqContext $resolveFaqContext,
        private ChatbotAiAdapter $chatbotAiAdapter,
    ) {}

    /**
     * Coordinate deterministic routing, authoritative context, and response wording.
     *
     * @return array{category: ChatbotQueryCategory, message: string, source: 'gemini'|'fallback'}
     */
    public function respond(string $message, ?User $customer = null): array
    {
        $normalizedMessage = Str::of($message)->trim()->squish()->toString();
        $category = $this->categorizeChatbotQuery->execute($normalizedMessage);

        if ($normalizedMessage === '') {
            return $this->fallback($category, self::EMPTY_MESSAGE);
        }

        if ($category === ChatbotQueryCategory::Unsupported) {
            return $this->fallback($category, self::UNSUPPORTED_INQUIRY);
        }

        if ($category === ChatbotQueryCategory::Order && $customer === null) {
            return $this->fallback($category, self::UNAUTHENTICATED_ORDER);
        }

        $context = $this->resolveContext($category, $normalizedMessage, $customer);

        if ($this->hasNoContext($category, $context)) {
            return $this->fallback(
                $category,
                $this->missingContextMessage($category, $customer),
            );
        }

        $result = $this->chatbotAiAdapter->generate($normalizedMessage, $context);

        if ($result['successful']) {
            return [
                'category' => $category,
                'message' => $result['text'],
                'source' => 'gemini',
            ];
        }

        return $this->fallback(
            $category,
            $result['failure'] === 'timeout'
                ? self::PROVIDER_TIMEOUT
                : self::PROVIDER_UNAVAILABLE,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveContext(
        ChatbotQueryCategory $category,
        string $message,
        ?User $customer,
    ): array {
        return match ($category) {
            ChatbotQueryCategory::Product => $this->resolveProductContext->execute($message),
            ChatbotQueryCategory::Order => $this->resolveOrderContext->execute($customer, $message),
            ChatbotQueryCategory::Store => $this->resolveStoreContext->execute($message),
            ChatbotQueryCategory::Faq => $this->resolveFaqContext->execute($message),
            ChatbotQueryCategory::Unsupported => [],
        };
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function hasNoContext(ChatbotQueryCategory $category, array $context): bool
    {
        return match ($category) {
            ChatbotQueryCategory::Product => $context['products'] === [],
            ChatbotQueryCategory::Order => $context['orders'] === [],
            ChatbotQueryCategory::Store => $context['branches'] === [],
            ChatbotQueryCategory::Faq => $context['knowledge'] === [],
            ChatbotQueryCategory::Unsupported => true,
        };
    }

    private function missingContextMessage(
        ChatbotQueryCategory $category,
        ?User $customer,
    ): string {
        return match ($category) {
            ChatbotQueryCategory::Product => self::NO_PRODUCT_MATCH,
            ChatbotQueryCategory::Order => $customer === null
                ? self::UNAUTHENTICATED_ORDER
                : self::ORDER_NOT_FOUND,
            ChatbotQueryCategory::Store => self::STORE_INFORMATION_UNAVAILABLE,
            ChatbotQueryCategory::Faq => self::FAQ_INFORMATION_UNAVAILABLE,
            ChatbotQueryCategory::Unsupported => self::UNSUPPORTED_INQUIRY,
        };
    }

    /**
     * @return array{category: ChatbotQueryCategory, message: string, source: 'fallback'}
     */
    private function fallback(ChatbotQueryCategory $category, string $message): array
    {
        return [
            'category' => $category,
            'message' => $message,
            'source' => 'fallback',
        ];
    }
}
