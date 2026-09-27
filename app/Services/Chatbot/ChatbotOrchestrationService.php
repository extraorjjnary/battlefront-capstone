<?php

namespace App\Services\Chatbot;

use App\Actions\Chatbot\Context\ResolveFaqContext;
use App\Actions\Chatbot\Context\ResolveOrderContext;
use App\Actions\Chatbot\Context\ResolveProductContext;
use App\Actions\Chatbot\Context\ResolveStoreContext;
use App\Actions\Chatbot\RouteChatbotQuery;
use App\Enums\ChatbotQueryCategory;
use App\Models\User;
use Closure;
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

    private const SENSITIVE_INPUT = 'Please remove sensitive information from your question and try again.';

    /** @var list<string> */
    private const SENSITIVE_INPUT_PATTERNS = [
        '/\b(?:password|passcode|api[\s_-]*(?:key|secret|token)|access[\s_-]*token|internal[\s_-]*note)\s*(?::|=|\bis\b)\s*\S+/iu',
        '/\bbearer\s+[a-z0-9._~+\/=\-]+/iu',
        '/\bpayment-proofs[\/\\\\]\S+/iu',
        '/\bpayment[\s_-]*proof(?:[\s_-]*path)?\s*[:=]\s*\S+/iu',
        '/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/iu',
        '/(?<!\d)(?:\+63[\s-]?|0)9(?:[\s-]?\d){9}(?!\d)/u',
    ];

    public function __construct(
        private RouteChatbotQuery $routeChatbotQuery,
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
    public function respond(string $message, ?User $customer = null, ?Closure $remember = null, ?int $productId = null): array
    {
        $normalizedMessage = Str::of($message)->trim()->squish()->toString();
        $routing = $this->routeChatbotQuery->execute($normalizedMessage);
        $category = $routing['category'];

        if ($normalizedMessage === '') {
            return $this->fallback($category, self::EMPTY_MESSAGE);
        }

        if ($category === ChatbotQueryCategory::Order && $customer === null && $routing['choices'] === []) {
            return $this->fallback($category, self::UNAUTHENTICATED_ORDER);
        }

        if ($this->containsSensitiveInput($normalizedMessage)) {
            return $this->fallback($category, self::SENSITIVE_INPUT);
        }

        if ($routing['choices'] !== []) {
            return $this->fallback($category, $this->routeChatbotQuery->clarification($routing['choices']));
        }

        if ($category === ChatbotQueryCategory::Unsupported) {
            return $this->fallback($category, self::UNSUPPORTED_INQUIRY);
        }

        if ($category === ChatbotQueryCategory::Order && preg_match('/\bbf[\s\W_]*\d+\b/iu', $normalizedMessage) !== 1) {
            return $this->fallback($category, 'Which order do you mean? Please provide its BF order reference from your order history.');
        }

        $scopeMessage = Str::of($normalizedMessage)->lower()->replaceMatches('/[^\p{L}\p{N}\s]+/u', ' ')->squish()->toString();
        if ($category === ChatbotQueryCategory::Product
            && preg_match('/\b(?:san carlos|escalante|guihulngan)\b/u', $scopeMessage) === 1
            && preg_match('/\b(?:stock|stocks|available|availability|availble|avalable|stok)\b/u', $scopeMessage) === 1) {
            return $this->fallback($category, 'Live inventory information covers the Sagay branch only. I cannot confirm stock at the other branches. Please ask about Sagay availability or contact the branch.');
        }

        $context = $productId !== null && $category === ChatbotQueryCategory::Product
            ? $this->resolveProductContext->forProduct($productId)
            : $this->resolveContext($category, $normalizedMessage, $customer);

        if ($this->hasNoContext($category, $context)) {
            return $this->fallback(
                $category,
                $this->missingContextMessage($category, $customer),
            );
        }

        $remember?->__invoke($category, $context);
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

    public function containsSensitiveInput(string $message): bool
    {
        foreach (self::SENSITIVE_INPUT_PATTERNS as $pattern) {
            if (preg_match($pattern, $message) === 1) {
                return true;
            }
        }

        return false;
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
