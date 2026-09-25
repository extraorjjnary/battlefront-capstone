---
paths:
    - 'app/Models/Product.php,app/Http/Controllers/Administration/ProductController.php,app/Http/Requests/Administration/SaveProductRequest.php'
---

# Administration

## Store product images as managed relative paths

Persist relative image_path values and resolve image_url through the public disk. ProductImagePaths is the shared ownership rule: manifest images use products/{category}/{code}.webp; admin uploads use products/admin/{product-id}/{content-sha256}.webp, with safe flat products/* uploads recognized for legacy cleanup. This supersedes deletion based only on the products/ prefix: delete only unreferenced admin-owned files after commit, never manifest images. Re-import preserves admin-owned active paths, and normal uploads must not depend on manifest state. is_catalog_imported protects the original product_code; it does not determine image ownership.
