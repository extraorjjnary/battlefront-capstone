---
paths:
  - 'app/{Ai,Services/Chatbot}/**'
---

# Chatbot

## Keep Gemini behind the chatbot adapter
Use ChatbotAiAdapter as the application boundary for Laravel AI SDK text generation. ChatbotResponseAgent is pinned to Gemini 2.5 Flash with a 20-second timeout; callers pass only application-prepared scalar/array context, never Eloquent models or provider credentials. Map provider failures to safe adapter results without logging prompts, context, secrets, or raw exception messages.
