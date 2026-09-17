---
paths:
    - 'app/Models/Product.php,app/Http/Controllers/Administration/ProductController.php,app/Http/Requests/Administration/SaveProductRequest.php'
---

# Administration

## Store product images as managed relative paths

Persist product image_path values as relative paths only. Admin uploads use the public disk under products/, while presentation resolves image_url from image_path. Delete replaced files only when the old path begins with products/ so seeded public demo assets are never removed.
