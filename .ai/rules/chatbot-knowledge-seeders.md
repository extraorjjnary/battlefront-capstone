---
paths:
  - 'app/Actions/Chatbot/**,app/Services/Chatbot/**,app/Http/Requests/Administration/*ChatbotKnowledge*.php,resources/js/pages/Administration/ChatbotKnowledge/**,database/seeders/DevelopmentChatbotKnowledgeSeeder.php'
---

# Chatbot Knowledge Seeders

## Keep managed FAQs separate from authoritative chatbot resolvers
The approved FAQ refinement supersedes the earlier FAQ+Order knowledge retrieval rule. Only active, eligible FAQ records participate in template matching; exactness and relevance select one answer, and equal best matches clarify before Gemini. Category and priority are server-owned in administration; retain their columns and legacy records. Live catalog/branch/personal-order facts and payment/fulfillment rules belong to application resolvers. Use ClassifyChatbotInquiry for runtime, save/reactivation validation, and preview; preserve edited/custom presets during data refinement.
