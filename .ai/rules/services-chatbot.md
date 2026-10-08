---
paths:
  - 'app/Services/Chatbot/**'
---

# Services Chatbot

## Gate Gemini behind authoritative chatbot context
Chatbot orchestration must normalize and categorize first, resolve only the selected category's authoritative context second, and call ChatbotAiAdapter only when that context is non-empty. Unsupported, missing-data, order-privacy, timeout, and provider-failure paths return predefined fallbacks without inventing facts.

## Gate Gemini behind authoritative chatbot context
Normalize and route first, resolve only the selected category, and call ChatbotAiAdapter only after privacy and non-empty-context checks. On provider failure, RenderChatbotFallback formats existing resolver facts/approved templates; clarify ambiguous matches, preserve unavailable fields and demo disclaimers. Requery ownership and live facts on follow-ups. No automatic retries, alternate provider, or provider bypass is enabled.
