---
paths:
    - 'app/Services/Chatbot/**'
---

# Services Chatbot

## Gate Gemini behind authoritative chatbot context

Chatbot orchestration must normalize and categorize first, resolve only the selected category's authoritative context second, and call ChatbotAiAdapter only when that context is non-empty. Unsupported, missing-data, order-privacy, timeout, and provider-failure paths return predefined fallbacks without inventing facts.

## Gate Gemini behind authoritative chatbot context

Normalize and route first, resolve only the selected category, and call ChatbotAiAdapter only after privacy and non-empty-context checks. On provider failure, RenderChatbotFallback formats existing resolver facts/approved templates; clarify ambiguous matches, preserve unavailable fields and demo disclaimers. Requery ownership and live facts on follow-ups. No automatic retries, alternate provider, or provider bypass is enabled.

## Return approved FAQs and application rule guidance directly
The developer-approved direct-answer refinement supersedes the prior provider-bypass prohibition for FAQ-category responses. After shared ownership, privacy, ambiguity and nonempty-context checks, return the one selected current active eligible FAQ answer or application payment/fulfillment guidance directly, preserving approved wording and conversation references. Gemini remains behind ChatbotAiAdapter for live product, branch and personal-order contexts. Preserve the existing gemini/fallback response-source contract; fallback denotes deterministic replies too.
