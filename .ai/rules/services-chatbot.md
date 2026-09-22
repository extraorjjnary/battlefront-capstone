---
paths:
  - 'app/Services/Chatbot/**'
---

# Services Chatbot

## Gate Gemini behind authoritative chatbot context
Chatbot orchestration must normalize and categorize first, resolve only the selected category's authoritative context second, and call ChatbotAiAdapter only when that context is non-empty. Unsupported, missing-data, order-privacy, timeout, and provider-failure paths return predefined fallbacks without inventing facts.
