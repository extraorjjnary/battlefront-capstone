---
paths:
    - 'app/Actions/Chatbot/**,app/Services/Chatbot/**,resources/js/components/chatbot/**'
---

# Components Chatbot

## Keep chatbot follow-ups bounded and authoritative

Use RouteChatbotQuery and MatchChatbotKnowledge for customer routing and admin previews. Resolve one selected topic; clarify independent mixed topics. Current-chat context is a 15-minute encrypted token bound to session and user, held only in Vue memory and reset on identity change or New chat. Retain entity references and pending clarification only; requery live facts and order ownership each turn. Explicit entity names/references override previous context. Knowledge templates must not override live catalog, branch, or personal order facts.
