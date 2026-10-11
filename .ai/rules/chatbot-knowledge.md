---
paths:
  - 'app/Actions/Chatbot/**,app/Http/Controllers/Administration/ChatbotKnowledgeController.php,resources/js/pages/Administration/ChatbotKnowledge/**'
---

# Chatbot Knowledge

## Hide unchanged retired chatbot presets from administration lists
Keep retired preset records inactive in the database, but exclude them before paginating the FAQ management list. Identify retirement by the original category, question, answer, priority and inactive state; preserve visibility of edited/custom legacy entries and inactive usable FAQs. Retained detail pages label these presets Retired and offer no edit/reactivate controls. Fresh development resets seed four managed FAQs, not the former eleven mixed templates.

## Delete unchanged retired chatbot presets after approved cleanup
The developer-approved cleanup supersedes the earlier rule retaining and hiding retired presets. A forward migration deletes only inactive records matching each frozen original category, question, answer and priority exactly; preserve edited/custom records, active records and current managed FAQs. Remove the retirement helper, list exclusions and is_retired UI metadata; deleted records return 404. Fresh resets continue to seed four FAQs.
