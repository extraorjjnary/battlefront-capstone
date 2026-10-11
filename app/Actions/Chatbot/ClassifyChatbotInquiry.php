<?php

namespace App\Actions\Chatbot;

use App\Enums\ChatbotQueryCategory;
use Illuminate\Support\Str;

class ClassifyChatbotInquiry
{
    /** @var list<string> */
    private const SENSITIVE_INPUT_PATTERNS = [
        '/\b(?:password|passcode|api[\s_-]*(?:key|secret|token)|access[\s_-]*token|internal[\s_-]*note)\s*(?::|=|\bis\b)\s*\S+/iu',
        '/\bbearer\s+[a-z0-9._~+\/=\-]+/iu',
        '/\bpayment-proofs[\/\\\\]\S+/iu',
        '/\bpayment[\s_-]*proof(?:[\s_-]*path)?\s*[:=]\s*\S+/iu',
        '/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/iu',
        '/(?<!\d)(?:\+63[\s-]?|0)9(?:[\s-]?\d){9}(?!\d)/u',
    ];

    public function __construct(private CategorizeChatbotQuery $categorizer = new CategorizeChatbotQuery) {}

    /**
     * Classify ownership without retrieving records or invoking a provider.
     *
     * @return array{category: ChatbotQueryCategory, owner: string, eligible_for_faq: bool, reason: string, rule_topic: string|null, choices: array<string, string>}
     */
    public function execute(string $message): array
    {
        $result = $this->classifySingle($message);
        if ($this->containsSensitiveInput($message) || $this->categorizer->isBlocked($message)) {
            return $result;
        }

        $choices = [];
        $clauses = preg_split('/\s+(?:and|also|plus)\s+|[;?]+\s*(?:(?:and|also|plus)\s+)?/iu', $message, flags: PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($clauses as $clause) {
            $classified = $this->classifySingle($clause);
            if ($classified['category'] !== ChatbotQueryCategory::Unsupported) {
                $category = $classified['category']->value;
                $choices[$category] = isset($choices[$category]) ? $choices[$category].' and '.trim($clause) : trim($clause);
            }
        }
        if (count($choices) > 1) {
            $result['owner'] = 'clarification';
            $result['eligible_for_faq'] = false;
            $result['reason'] = 'Use one support question per FAQ; this wording mixes independent topics.';
            $result['choices'] = $choices;
        }

        return $result;
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

    /** @return array{category: ChatbotQueryCategory, owner: string, eligible_for_faq: bool, reason: string, rule_topic: string|null, choices: array<string, string>} */
    private function classifySingle(string $message): array
    {
        $category = $this->categorizer->execute($message);
        $text = Str::of($message)->lower()->replaceMatches('/[^\p{L}\p{N}\s]+/u', ' ')->squish()->toString();
        $owner = $category->value;
        $reason = '';
        $topic = null;
        $eligible = false;

        if ($this->containsSensitiveInput($message)) {
            $owner = 'unsupported';
            $reason = 'Remove sensitive information before saving or testing this question.';
        } elseif ($this->categorizer->isBlocked($message)) {
            $owner = 'unsupported';
            $reason = 'FAQs must contain approved Battlefront support guidance, not unsupported requests.';
        } elseif (preg_match('/\bbf\s*\d+\b/u', $text) === 1) {
            $category = ChatbotQueryCategory::Order;
            $owner = 'order';
        } elseif ($this->isNavigationGuidance($text) && preg_match('/\b(?:price|prices|cost|stock|stocks|available|availability|specs|specifications)\b/u', $text) !== 1) {
            $category = ChatbotQueryCategory::Faq;
            $owner = 'managed_faq';
            $eligible = true;
        } elseif ($category === ChatbotQueryCategory::Order) {
            $owner = 'order';
        } elseif ($this->categorizer->requiresLiveFacts($message) && $this->ruleTopic($text) !== 'delivery_quote') {
            $category = $this->categorizer->requiresStoreFacts($message) ? ChatbotQueryCategory::Store : ChatbotQueryCategory::Product;
            $owner = $category->value;
        } elseif (! in_array($category, [ChatbotQueryCategory::Product, ChatbotQueryCategory::Store], true) && ($topic = $this->ruleTopic($text)) !== null) {
            $category = ChatbotQueryCategory::Faq;
            $owner = 'application_rules';
            $reason = 'This question is answered from application payment or fulfillment rules. It cannot be overridden by an FAQ.';
        } elseif ($category === ChatbotQueryCategory::Product || $category === ChatbotQueryCategory::Store || $this->categorizer->requiresLiveFacts($message)) {
            $owner = $category === ChatbotQueryCategory::Store ? 'store' : 'product';
        } else {
            $owner = 'managed_faq';
            $eligible = true;
        }

        if ($reason === '') {
            $reason = match ($owner) {
                'product' => 'Product answers use current catalog and Sagay inventory facts. Missing product identification is handled by the product resolver, not FAQ templates.',
                'store' => 'Store answers use confirmed branch information, not FAQ templates.',
                'order' => 'Personal order inquiries require a customer account and an owned BF order reference, not an FAQ.',
                default => 'Eligible for an approved Battlefront support FAQ. Saving content is an administrator approval decision.',
            };
        }

        return ['category' => $category, 'owner' => $owner, 'eligible_for_faq' => $eligible, 'reason' => $reason, 'rule_topic' => $topic, 'choices' => []];
    }

    private function isNavigationGuidance(string $text): bool
    {
        return preg_match('/\b(?:how (?:can|do|should) i (?:check|view|find|see|track)|where (?:can|do) i (?:check|view|find|see))\b.*\b(?:order history|(?:my )?order status|previous orders|past orders)\b/u', $text) === 1
            || preg_match('/\bhow (?:can|do|should) i (?:search|filter|browse)\b.*\b(?:products?|catalog)\b/u', $text) === 1
            || preg_match('/\bhow (?:can|do|should) i (?:view|open)\b.*\b(?:product s details|product details|catalog)\b/u', $text) === 1;
    }

    private function ruleTopic(string $text): ?string
    {
        if (preg_match('/\b(?:delivery fee|delivery fees|shipping fee|shipping cost|delivery cost|delivery time|delivery estimate|estimated delivery|delivery eta)\b/u', $text) === 1) {
            return 'delivery_quote';
        }
        if (preg_match('/\b(?:payment|paymnt|pay|cash|card|gcash|maya)\b/u', $text) === 1) {
            return preg_match('/\b(?:proof|proofs|verify|verified|verifying|verification)\b/u', $text) === 1 ? 'payment_proof' : 'payment_methods';
        }
        if (preg_match('/\b(?:pick up|pickup|fulfillment|do you deliver|delivery options?|delivry|choose.*delivery|delivery or pickup)\b/u', $text) === 1) {
            return 'fulfillment';
        }

        return null;
    }
}
