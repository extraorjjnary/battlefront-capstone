---
paths:
    - 'app/Services/AdministrationEvidenceService.php,app/Http/Controllers/Administration/CustomerReturnController.php'
---

# Controllers Administration

## Close evidence uploads by case stage and payment ownership

Lock and revalidate evidence subjects inside the upload transaction. Return/inspection evidence accepts multiple authorized inspectors only while pending review. Refund proof belongs to its specific payment and original recorder, may be supplemented while unreviewed/disputed, and closes on verified/voided. Preserve historical read access, secure storage, five-file limits and append-only evidence.

## Require Staff-only product photos for return receipt

Supersedes multi-role return-inspection uploads: only Staff with orders.view and returns.request may receive new customer returns or upload product images while pending review. Require 1–5 JPG/PNG/WebP photos, 5 MB each; Manager/Owner reads product evidence only. Preserve legacy attachments and separate payment-proof ownership/stage rules; a PDF or missing file cannot satisfy approval's accessible-product-photo requirement.

## Share only explicitly designated new refund payment proof

New approver-confirmed noncash payments attach one image atomically before their final confirmation; cash attaches none. Only these explicitly designated images have customer_visible=true. Historical evidence remains private by default. Customer proof access must enforce order ownership, payment/evidence linkage, final approver confirmation and existing file presence; never publish storage paths. Product receipt photos remain Staff-only and read-only for Manager/Owner. Legacy payment proof ownership and stage restrictions remain unchanged.
