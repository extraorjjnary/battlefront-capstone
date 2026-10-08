---
paths:
    - 'app/Actions/Chatbot/**,database/seeders/DevelopmentChatbotKnowledgeSeeder.php'
---

# Seeders

## Keep chatbot routing separate from knowledge topics

ChatbotQueryCategory chooses the runtime resolver, while ChatbotCategory labels persisted knowledge by topic. The FAQ resolver may use active FAQ and Order knowledge because approved payment and pickup/delivery guidance is stored under the Order topic; do not persist Unsupported knowledge or treat the enums as interchangeable.
