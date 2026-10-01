# React Native API handoff — EXT-62

This document describes the implemented customer REST API, including guest chatbot access. Laravel owns all catalog, cart, checkout, order, recommendation, and chatbot business rules. React Native is a separate client. Administration remains on the web.

Companion files:

- [Postman collection](Battlefront_API_v1.postman_collection.json)
- [Postman environment](Battlefront_API_v1.postman_environment.json)

Examples below contain synthetic data, illustrative IDs and dates. They are shapes, not seeded records or credentials. Discover current IDs and options from the running application.

## 1. Connection and common contract

Set Postman's `base_url` to the reachable backend root **including `/api/v1`**, without a trailing slash. Requests use `{{base_url}}/products`, etc. The checked local Herd health URL is `http://battlefront-capstone.test/api/v1/health`; that host may resolve only on the development computer.

For a phone on the same LAN, bind the development server to an accessible interface, for example `php artisan serve --host=0.0.0.0 --port=8000`, allow the chosen development port through the host firewall, and use the development computer's reachable host/address in the Postman/mobile configuration. `0.0.0.0` is a listening address, not the client destination. A phone's localhost refers to the phone. Do not hard-code LAN addresses in application code. Confirm that returned image and pagination URLs are also reachable from the device. Native mobile HTTP does not use browser CORS; browser-based tooling may have different requirements. Use HTTPS for deployed credentials.

Send `Accept: application/json`. JSON bodies use `Content-Type: application/json`; uploads use multipart with a client-generated boundary. API paths render JSON without needing the Accept header, but clients should send it.

- Resources: `{"data": {...}}`.
- Unpaginated lists: `{"data": [...]}`; an empty result is `{"data":[]}`.
- Paginated lists: `data`, `links`, `meta`.
- No additional success flag/message envelope.
- Money is a decimal **string in Philippine pesos**. Preserve precision; server totals are authoritative.
- IDs/counts/quantities are integers in responses. Nullable fields must be handled explicitly.
- Logout is the exception: HTTP `204`, **no body**. Do not parse JSON for it.

### Errors

| HTTP | Meaning / shape |
|---|---|
| 401 | Missing/invalid required bearer auth: `{"message":"Unauthenticated."}`; invalid login (including administrators): `{"message":"Invalid credentials."}` |
| 403 | Valid authenticated identity is not allowed; JSON `message` |
| 404 | Unknown route/product or missing/foreign cart item/order; JSON `message`; do not rely on exact message text |
| 422 | Request validation or business conflict; `message` plus `errors` mapping field names to arrays of messages |
| 429 | Rate limit reached; JSON `message`, `Retry-After` header |
| 500 | With `APP_DEBUG=false`: `{"message":"Server Error"}`. Debug deployments can include diagnostic fields; these are not a client contract. |

Representative quantity error:

```json
{
  "message": "Quantity must be at least 1.",
  "errors": {
    "quantity": [
      "Quantity must be at least 1."
    ]
  }
}
```

Stock conflicts use **422, not 409**. Display field messages instead of parsing the summary string. Array validation can use dotted keys such as `tag_ids.0`. Reverse proxies/PHP upload limits may reject oversized requests before Laravel's field validation.

### Pagination

`GET /products?page=1`: fixed **12/page**; featured first, then name, then ID.
`GET /orders?page=1`: fixed **10/page**; newest creation timestamp, then highest ID.
There is no configurable `per_page` parameter.

Both return `links.first/last/prev/next` and `meta.current_page/from/last_page/links/path/per_page/to/total`. Previous/next links can be null; `from/to` are null for an empty page. Catalog links preserve supported filters. Use `links.next` or page metadata; do not assume every page is full. Numbered metadata links include `url`, `label`, `page`, `active`; ellipsis entries may omit `page`.

Catalog validates `page` as an integer >=1; order history uses Laravel's paginator resolution without an equivalent FormRequest page rule. Do not assume invalid order pages yield catalog-style 422 errors.

### Rate limits

| Scope | Limit |
|---|---|
| API v1 | 60 requests/minute/IP across the version group |
| Registration | Additional 5/minute/IP |
| Login | Additional 5/minute/lowercased email + IP, shared named login limiter |
| Chatbot guest | Additional 5/minute/IP, shared with web guests |
| Chatbot customer | Additional 10/minute/customer, shared across tokens/devices and web |

The chatbot-specific 429 message is `Too many questions. Please wait a minute and try again.` The general API limit can be reached first. Respect `Retry-After`. Limits count requests, not only failed attempts. Users behind one LAN can share the IP allowance.

## 2. Authentication and access

Bearer header: `Authorization: Bearer <token>`. No session login, CSRF-cookie request, or Fortify flow is needed for the mobile API. Fortify remains the web authentication flow.

Registration/login create a new Sanctum personal access token with a **30-day per-token expiration**. The response includes `expires_at`; store the raw token securely on the device. Tokens are only returned at creation/login. There is no refresh endpoint or token listing endpoint. Login again after expiry. Multiple devices can have separate tokens; logout deletes only the token presented for that request.

### Complete endpoint inventory

All paths below are relative to `base_url`. GET routes also support HEAD through Laravel.

| Method | Path | Access | Success |
|---|---|---|---|
| GET | /health | Public | 200 |
| POST | /auth/register | Public | 201 |
| POST | /auth/login | Public | 200 |
| POST | /auth/logout | Customer | 204 |
| GET | /profile | Customer | 200 |
| PATCH | /profile | Customer | 200 |
| GET | /products | Public | 200 |
| GET | /products/filters | Public | 200 |
| GET | /products/{product} | Public | 200 |
| GET | /branches | Public | 200 |
| GET | /cart | Customer | 200 |
| POST | /cart/items | Customer | 200 |
| PATCH | /cart/items/{cartItem} | Customer | 200 |
| DELETE | /cart/items/{cartItem} | Customer | 200 |
| GET | /checkout | Customer | 200 |
| POST | /orders | Customer | 201 |
| GET | /orders | Customer | 200 |
| GET | /orders/{order} | Customer | 200 |
| POST | /orders/{order}/payment-proof | Customer | 200 |
| POST | /chatbot | Guest/customer | 200 |
| GET | /recommendations/options | Guest/customer | 200 |
| POST | /recommendations | Guest/customer | 200 |

Public health/catalog/branches do not authenticate supplied credentials. Registration/login authenticate their submitted fields, not a bearer header.

For **chatbot and both recommendation routes**: omit Authorization entirely for guests; valid customer tokens are accepted; valid administrator tokens get 403; invalid/expired/revoked/malformed supplied Authorization gets 401. An empty bearer header is not a guest request. Web sessions do not authenticate these API requests.

Customer-only routes require Sanctum plus the customer role gate: missing/invalid/expired/revoked token gets 401; administrator token gets 403. Foreign cart/order IDs are concealed with 404. Chatbot order ownership failures instead use the safe conversational fallback described below. Validation may run before resource lookup, so use otherwise valid input when testing ownership.

## 3. Registration, login, profile

### POST /auth/register

Required fields:

| Field | Validation |
|---|---|
| name | String, max 255 |
| email | Valid email string, max 255, unique; lowercased by current Fortify setting |
| password | String, confirmed by password_confirmation |
| password_confirmation | Must match password |
| device_name | String, max 255 |

The password default outside production is minimum 8 characters. Production config requires at least 12, upper/lowercase, letters, numbers, symbols, and uncompromised-password validation. Use a suitable local test password rather than a committed example.

The shared request also validates optional `default_delivery_address` (nullable string, max 255), **but registration does not persist it**. Send it in the profile update instead. Submitted role/ownership fields do not create administrators.

### POST /auth/login

Required: `email` (valid email string), `password` (string), `device_name` (string, max 255). No password_confirmation. Invalid credentials and administrator credentials both return the same 401 and issue no token.

Both authentication success responses use:

```json
{
  "data": {
    "user": {
      "id": 1,
      "name": "Example Customer",
      "email": "customer@example.test",
      "default_delivery_address": null
    },
    "token": "<issued-only-at-runtime>",
    "token_type": "Bearer",
    "expires_at": "2026-10-31T08:00:00+00:00"
  }
}
```

### POST /auth/logout

No body. Requires current customer token; 204 with an empty body. Remove the client token and clear conversation context. Subsequent use of the revoked token returns 401.

### GET /profile and PATCH /profile

GET returns:

```json
{
  "data": {
    "id": 1,
    "name": "Example Customer",
    "email": "customer@example.test",
    "default_delivery_address": null
  }
}
```

PATCH requires `name` and `email` (same length/email/uniqueness rules, excluding the current user). Optional `default_delivery_address` is nullable/max 255: omit to preserve, send null/blank to clear. Email changes clear the stored email verification timestamp; verification is currently optional/disabled.

```json
{
  "name": "Example Customer",
  "email": "customer@example.test",
  "default_delivery_address": "Example delivery address"
}
```

Successful PATCH returns the same four-field profile shape. No user ID is accepted to select a profile. No password, role, remember token, recovery code, or two-factor data is exposed.

## 4. Catalog and branches

### GET /products

Optional query parameters:

| Parameter | Meaning |
|---|---|
| q | Nullable string <=255; substring search across product name, brand, description |
| category_id | Nullable integer; existing active category |
| brand | Nullable string <=255; catalog brand equality filter |
| tag_id | Nullable integer; existing tag (single tag filter) |
| page | Nullable integer >=1 |

Filters combine. There is no stock, budget, sort, or multi-tag catalog filter. Use `GET /products/filters` to populate selectors:

```json
{
  "data": {
    "categories": [
      {
        "id": 1,
        "name": "Peripherals"
      }
    ],
    "brands": [],
    "tags": [
      {
        "id": 1,
        "name": "Gaming"
      }
    ]
  }
}
```

List entries and `GET /products/{product}` share this product shape (detail wraps it in `data`):

```json
{
  "data": {
    "id": 1,
    "name": "Example Mouse",
    "description": null,
    "brand": null,
    "price": "100.00",
    "discount_price": null,
    "image_url": null,
    "is_featured": false,
    "category": {
      "id": 1,
      "name": "Peripherals"
    },
    "tags": [
      {
        "id": 1,
        "name": "Gaming"
      }
    ],
    "inventory": {
      "status": "in_stock"
    }
  }
}
```

Active products in active categories are eligible. Inactive products/category products are omitted from lists and return 404 on detail. Missing or nonnumeric product IDs return 404. Eligible products with no inventory or zero inventory **remain visible**.

`inventory.status` is one of `in_stock`, `low_stock`, `out_of_stock`, `unavailable`. It reflects current Sagay inventory; exact stock quantity is omitted. Use `discount_price ?? price` as effective price. Brand, description, image_url and discount_price may be null. Image URLs come from Laravel's configured asset/storage URLs.

A list wraps these entries in the pagination structure described above. No matches yields an empty paginated collection.

### GET /branches

Unpaginated `data` array. Returns current static directory rows, operational Sagay first then city alphabetically. No branch detail API is implemented. Current seeded directory includes five branches; do not hard-code record IDs/counts or invent unconfirmed details.

```json
{
  "data": [
    {
      "id": 1,
      "name": "Example Store",
      "address": null,
      "city": "Sagay City",
      "contact_number": null,
      "latitude": null,
      "longitude": null,
      "email": null,
      "operating_hours": "8:00 AM–6:00 PM",
      "is_operational": true
    }
  ]
}
```

`id` integer; `name/city/operating_hours` strings; `is_operational` boolean; address/contact/email nullable strings; latitude/longitude nullable numbers. Other branches provide static reference information, not selectable live inventory pools.

## 5. Cart

All operations address the authenticated customer's cart. No cart ID/user ID is needed.

- GET /cart: empty or populated cart.
- POST /cart/items: required `product_id` (existing integer ID), `quantity` (integer 1..4294967295). Adds to existing line quantity.
- PATCH /cart/items/{cartItem}: required `quantity` with the same range. Replaces the quantity.
- DELETE /cart/items/{cartItem}: no body. Removes a line, including unavailable products.

All successful operations return **200 with the full refreshed cart**:

```json
{
  "data": {
    "items": [
      {
        "id": 1,
        "quantity": 2,
        "product": {
          "id": 1,
          "name": "Example Mouse",
          "brand": null,
          "image_url": null,
          "category": "Peripherals",
          "price": "100.00",
          "discount_price": null
        },
        "unit_price": "100.00",
        "line_total": "200.00",
        "availability": {
          "status": "available",
          "available_quantity": 5
        }
      }
    ],
    "item_count": 1,
    "total_quantity": 2,
    "total": "200.00",
    "conflict_count": 0
  }
}
```

`item_count` counts lines; `total_quantity` sums units; `total` uses current effective prices and BCMath decimal arithmetic. The nested cart product's `category` is a string, unlike catalog's category object.

Cart availability statuses: `available`, `product_ineligible`, `inventory_unavailable`, `out_of_stock`, `insufficient_stock`. `available_quantity` is integer or null. `conflict_count` counts lines not marked available.

Cart mutation rechecks active product/category and current Sagay stock. Adding is cumulative and must fit stock. Zero quantity does not delete a line. Ineligible/missing inventory errors use `errors.product_id`; zero/insufficient stock use `errors.quantity`. Foreign/missing line IDs return 404. Supplied prices/ownership are ignored. Cart does not reserve/deduct inventory; checkout rechecks it.

Empty cart:

```json
{
  "data": {
    "items": [],
    "item_count": 0,
    "total_quantity": 0,
    "total": "0.00",
    "conflict_count": 0
  }
}
```

## 6. Checkout, orders, payment evidence

### GET /checkout

Requires a nonempty conflict-free cart, otherwise 422 `errors.cart`. Returns a current snapshot and payment/fulfillment options:

```json
{
  "data": {
    "cart": {
      "items": [
        {
          "id": 1,
          "quantity": 2,
          "product": {
            "id": 1,
            "name": "Example Mouse",
            "brand": null,
            "image_url": null
          },
          "unit_price": "100.00",
          "line_total": "200.00"
        }
      ],
      "item_count": 1,
      "total_quantity": 2,
      "total": "200.00"
    },
    "customer": {
      "name": "Example Customer",
      "default_delivery_address": null
    },
    "pickup_location": {
      "name": "Example Store — Sagay City",
      "address": null,
      "contact_number": null,
      "operating_hours": "8:00 AM–6:00 PM"
    },
    "fulfillment_methods": [
      {
        "value": "pickup",
        "label": "Pickup"
      },
      {
        "value": "delivery",
        "label": "Delivery"
      }
    ],
    "payment_methods": [
      {
        "value": "cash",
        "label": "Cash",
        "requires_proof": false,
        "payment_account": null,
        "available_for": [
          "pickup"
        ]
      },
      {
        "value": "card_at_store",
        "label": "Card at store",
        "requires_proof": false,
        "payment_account": null,
        "available_for": [
          "pickup"
        ]
      },
      {
        "value": "gcash",
        "label": "GCash",
        "requires_proof": true,
        "payment_account": {
          "account_name": "Example demo account",
          "account_number": "EXAMPLE",
          "is_demo": true
        },
        "available_for": [
          "pickup",
          "delivery"
        ]
      },
      {
        "value": "maya",
        "label": "Maya",
        "requires_proof": true,
        "payment_account": {
          "account_name": "Example demo account",
          "account_number": "EXAMPLE",
          "is_demo": true
        },
        "available_for": [
          "pickup",
          "delivery"
        ]
      }
    ]
  }
}
```

Checkout cart lines omit the ordinary cart's availability and category/price fields; use the shown snapshot shape. `payment_account` is null for cash/card, or an object with account_name/account_number/is_demo for wallets. Display configured values from the response and honor demo labeling; the example account above is synthetic. No transfer is initiated by this API.

### POST /orders

Required fields:

| Field | Rule |
|---|---|
| recipient_name | String <=255 |
| contact_number | String <=20; preserve formatting as text |
| fulfillment_method | pickup or delivery |
| payment_method | cash, card_at_store, gcash, maya |
| delivery_address | String <=255, required for delivery; prohibited when nonempty for pickup |
| payment_proof | Required for gcash/maya; prohibited for cash/card_at_store |

Pickup accepts all four payment methods. Delivery accepts only gcash/maya. Submit JSON for cash/card pickup:

```json
{
  "recipient_name": "Example Customer",
  "contact_number": "EXAMPLE",
  "fulfillment_method": "pickup",
  "payment_method": "cash"
}
```

For wallet orders submit the fields above using multipart form data, including an actual file part named `payment_proof`. JPEG/JPG, PNG, WebP only; contents must be an image, filename extension must match allowed extensions; max **5120 KB (5 MB)**. Do not send a filesystem path or base64 string as the proof, and do not manually specify a multipart boundary/Content-Type in Postman or React Native FormData.

The server places the entire current cart through shared transactional order placement, locks/rechecks stock, snapshots prices/recipient/fulfillment, deducts inventory and clears the cart atomically. Order and payment initially remain pending; payment verification is manual. Failed stock validation uses 422 `errors.cart` and rolls back the operation.

Do not send client totals, item prices, inventory adjustments or a user ID. There is no idempotency-key contract: a repeated request after successful cart consumption fails on the empty cart. On an uncertain network outcome, inspect history before attempting a new placement; do not assume retries return the original order.

201 response, also the shape for GET /orders/{order} and successful proof replacement:

```json
{
  "data": {
    "id": 1,
    "reference": "BF-000001",
    "created_at": "2026-10-01T08:00:00+00:00",
    "status": {
      "value": "pending",
      "label": "Pending"
    },
    "recipient": {
      "name": "Example Customer",
      "contact_number": "EXAMPLE"
    },
    "fulfillment": {
      "value": "pickup",
      "label": "Pickup",
      "delivery_address": null
    },
    "payment": {
      "method": {
        "value": "cash",
        "label": "Cash"
      },
      "status": {
        "value": "pending",
        "label": "Pending"
      },
      "proof_submitted": false,
      "notice": "Payment will be handled when you collect your order.",
      "rejection": null,
      "can_resubmit_proof": false
    },
    "items": [
      {
        "id": 1,
        "product": {
          "id": 1,
          "name": "Example Mouse",
          "brand": null,
          "image_url": null
        },
        "quantity": 2,
        "unit_price": "100.00",
        "line_total": "200.00"
      }
    ],
    "item_count": 1,
    "total_quantity": 2,
    "total": "200.00"
  }
}
```

Order item prices/quantities are persisted snapshots; displayed product names/brand/image come from current related product records. `payment.rejection` is null or `{"reason":"customer-facing explanation","note":null}` (note may be string). Use returned `can_resubmit_proof`; no proof path or download URL is exposed.

Order status values: pending, processing, completed, cancelled. Labels reflect fulfillment (e.g. Preparing for pickup / Preparing for delivery). Payment statuses: pending, verified, rejected. They are separate state machines; proof submission is not payment verification.

### GET /orders and GET /orders/{order}

History is customer-owned, paginated at 10. Each `data` entry is:

```json
{
  "id": 1,
  "reference": "BF-000001",
  "created_at": "2026-10-01T08:00:00+00:00",
  "status": {
    "value": "pending",
    "label": "Pending"
  },
  "fulfillment": {
    "value": "pickup",
    "label": "Pickup"
  },
  "payment": {
    "method": {
      "value": "cash",
      "label": "Cash"
    },
    "status": {
      "value": "pending",
      "label": "Pending"
    }
  },
  "item_count": 1,
  "total_quantity": 2,
  "total": "200.00"
}
```

Detail is customer-owned and uses the full order shape above. Missing/foreign IDs get 404. No customer cancellation, status mutation, payment verification or inventory restoration endpoint is exposed. Existing administrator cancellation logic owns restoration; reading an order does not restore inventory.

### POST /orders/{order}/payment-proof

Multipart with one required `payment_proof` file; same image/extension/size rules as initial upload. This replaces evidence for an owned **GCash/Maya order with rejected payment**, provided the order is neither completed nor cancelled.

200 returns full order detail, resets payment status to pending and clears rejection feedback. Order status/inventory stay unchanged. Other payment states/methods/terminal orders return 422 `errors.payment_proof`; valid uploads against foreign/missing orders return 404.

There is no separate initial-proof upload endpoint: initial wallet proof belongs to POST /orders.

## 7. Chatbot (guest and customer)

POST /chatbot JSON:

```json
{
  "message": "Where is the Sagay store?",
  "context_token": null
}
```

`message`: required nonblank string <=1000. `context_token`: optional nullable string <=16384.

```json
{
  "data": {
    "message": "Please sign in with a customer account to check order status.",
    "source": "fallback",
    "context_token": "<opaque-context-returned-at-runtime>"
  }
}
```

The example response illustrates a guest order question. Actual text depends on the question and authoritative data.

- Public product/store/FAQ questions work without Authorization.
- Personal order questions require a customer bearer token. A guest gets 200/fallback with `Please sign in with a customer account to check order status.`
- Customer questions resolve only owned orders; foreign/missing order references both return `I couldn't find a matching order in your account.`
- `source` is gemini or fallback. Provider failure/timeout/empty output returns the shared safe fallback with **200**.
- Unsupported/open-domain/recommendation questions do not become AI recommendations. Recommendations use their own deterministic endpoint.
- `context_token` is a string or null. Pass the latest one for follow-ups; it expires after 15 minutes. Invalid/tampered/expired context is ignored, not an authentication failure.
- Customer context is bound to the account and bearer token; guest context is scoped to the public API guest flow, not to an individual device/IP. Possession can continue public context but never supplies a customer identity. Guest/customer/web scopes cannot substitute for each other.
- Keep context in current-chat memory and reset it on New chat, login/logout, account or bearer-token change. Never treat it as the auth token.

## 8. Recommendations

GET /recommendations/options returns:

```json
{
  "data": {
    "intended_uses": [
      {
        "value": "general_use",
        "label": "General use"
      },
      {
        "value": "office_work",
        "label": "Office / work"
      },
      {
        "value": "gaming",
        "label": "Gaming"
      },
      {
        "value": "networking_piso_wifi",
        "label": "Networking / Piso WiFi"
      },
      {
        "value": "content_creation",
        "label": "Content creation"
      },
      {
        "value": "streaming",
        "label": "Streaming"
      },
      {
        "value": "home_security",
        "label": "Home security"
      },
      {
        "value": "business_enterprise",
        "label": "Business / enterprise"
      }
    ],
    "filter_options": {
      "categories": [
        {
          "id": 1,
          "name": "Peripherals"
        }
      ],
      "brands": [],
      "tags": [
        {
          "id": 1,
          "name": "Gaming"
        }
      ]
    }
  }
}
```

The example filter lists are illustrative; the real lists come from current eligible catalog data. Intended-use values are fixed by the shared enum.

POST /recommendations:

```json
{
  "budget": "1000.00",
  "intended_use": "gaming"
}
```

| Field | Rule |
|---|---|
| budget | Required numeric positive peso value, 0.01..9999999999.99, at most 2 decimal places |
| intended_use | Required one of the values in options |
| preferred_brand | Optional nullable string <=255; trimmed; null/blank/omitted means no preference |
| category_id | Optional nullable integer, existing active category |
| tag_ids | Optional nullable array of distinct existing integer tag IDs |

`tag_ids` is a list, not catalog's singular `tag_id`. Missing optional inputs are valid.

```json
{
  "data": [
    {
      "product": {
        "id": 1,
        "name": "Example Mouse",
        "description": null,
        "brand": null,
        "price": "100.00",
        "discount_price": null,
        "image_url": null,
        "is_featured": false,
        "category": {
          "id": 1,
          "name": "Peripherals"
        },
        "tags": [
          {
            "id": 1,
            "name": "Gaming"
          }
        ],
        "inventory": {
          "status": "in_stock"
        }
      },
      "effective_price": "100.00",
      "reasons": [
        {
          "code": "within_budget",
          "value": "100.00"
        },
        {
          "code": "sagay_stock",
          "value": "available"
        },
        {
          "code": "intended_use_tag",
          "value": "Gaming"
        }
      ]
    }
  ]
}
```

Results are unpaginated; no match is 200 with `data: []`. The deterministic engine requires active catalog eligibility, positive current Sagay stock, within-budget effective price, intended-use signals, and any explicit category/brand restrictions. Preferred brand matching is case-insensitive and trimmed; null-brand products remain eligible when no brand preference is given and are excluded by explicit brand preference.

Order is intended-use match count descending, preferred-tag match count descending, effective price ascending, ID ascending. Preferred tags influence ranking rather than requiring every tag. Preserve the server order.

Reason entries have `code` and string `value`. Codes: within_budget, sagay_stock, intended_use_category, intended_use_tag, selected_category, preferred_brand, preferred_tag. Product shape matches catalog; exact inventory quantity is omitted. No AI, compatibility checking, configurator, or recommendation persistence is involved.

## 9. Postman setup and manual acceptance run

The collection uses the [Postman v2.1 JSON format](https://schema.postman.com/) and [environment scripting](https://learning.postman.com/docs/use/send-requests/variables/environment-variables). No generator package is required.

### Import and variables

1. Import both companion JSON files into the Postman app.
2. Select **Battlefront API v1 — local placeholders** as the active environment.
3. Set local values. Do not export/commit populated secrets or personal data. Sensitive variables are marked secret, but that is not a substitute for keeping exports clean.
4. Select individual requests or a prepared subset. **Do not blindly run every folder**: registration, login, logout, alternative order placements, uploads and negative checks have different prerequisites.

| Variables | Configuration |
|---|---|
| base_url | Reachable API root, ending /api/v1; blank in export |
| email, password, name, device_name | Your disposable local customer; credentials blank in export |
| token | Automatically captured after successful register/login; protected requests inherit it |
| product_id, category_id, tag_id, brand | Select actual values from catalog/detail/filter responses |
| cart_item_id | Captured after Add product; manually selectable from own cart |
| order_id, order_reference | Captured after placement; manually selectable from history |
| rejected_order_id | Owned wallet order prepared with rejected payment in web administration |
| quantity, page, budget, intended_use, search | Representative nonsecret defaults where useful; optional catalog filters initially disabled |
| recipient_name, contact_number, delivery_address | Locally supplied test checkout/profile values |
| chatbot_message, follow_up_message | Representative public questions provided |
| context_token, guest_context_token | Separate customer/guest continuation values captured by chatbot scripts |
| other_cart_item_id, other_order_id, other_order_reference | Another disposable customer's resources, for ownership checks |
| admin_token | Local administrator test token supplied out of band; mobile login cannot issue it |
| invalid_token | Enter any deliberately invalid string locally |
| revoked_token | Captured locally at successful logout for a revocation check |
| expired_token | A genuinely expired local test token supplied out of band |

No file path is committed. Select a file in each multipart request after import. IDs start blank so the collection cannot silently assume database IDs. Raw JSON scripts serialize environment values to preserve quotes/backslashes in names/passwords; edit the script template if changing the represented body. Requests never log tokens.

Successful login/registration replace `token`, clear conversation state and clear selected cart/order IDs. Logout captures the old token in `revoked_token` for the negative check, then clears `token` and both context values. Remove `revoked_token` after checking. Guest requests have explicit No Auth, even when a customer token is populated.

### Required manual sequence

Use a disposable development database/account with an active product, live Sagay stock, and seeded branch/reference data.

1. **Connectivity/public reads:** Health (200), product filters/list/detail and branches (200). Enable catalog filters individually; check pagination and nullable fields.
2. **Access:** Profile without token (401). Guest recommendations/options (200). Guest chatbot public question/follow-up (200). Guest order question returns sign-in fallback. Pace calls under guest limits.
3. **Authentication/profile:** Register a unique customer (201) OR log in (200); confirm token capture. Read/update profile (200), verify only approved fields. Invalid login gives 401; duplicate/invalid registration gives 422.
4. **Cart:** Add stock-eligible product (200), verify captured cart_item_id; update quantity and totals; remove (200). Add again before checkout. Try quantity zero, quantity above stock, and an unavailable product (422); failed operations must not corrupt the cart.
5. **Checkout/orders:** Preview checkout (200). Submit cash/card pickup (201), inspect order/history (200), and verify cart is empty. Refill before each alternative wallet placement. Select an image; test gcash/maya and delivery address rules. Verify order/payment initially pending and inventory deduction through existing web inventory.
6. **Replacement proof:** Reject the test wallet payment through existing web administration. Set rejected_order_id, select a replacement image, submit (200). Verify pending payment, cleared rejection, unchanged order status/stock. Retry while payment is pending (422). Test unsupported file type and >5 MB (422 when Laravel handles it).
7. **Customer chatbot/recommendations:** Ask about the captured own order reference. Check customer follow-up. Exercise recommendation preferences, omitted/null brand, a budget with no matches, ranking/reasons, and available stock. Compare with web using the same current data.
8. **Ownership/roles:** Prepare a second customer's cart/order and use their IDs under the first token: 404. Foreign order chatbot: safe 200 fallback. With an out-of-band valid administrator test token: profile/chatbot/recommendations 403. Invalid/expired/revoked credentials: 401. Web session alone is insufficient for personal API data.
9. **Rate limits:** After the window resets, send five guest unsupported chatbot questions (e.g. Tell me a joke.), then the dedicated sixth-request check expects 429/Retry-After. Customer limit is ten across web/devices. Registration/login have five-request limits; global limit is 60/IP. Perform these separately to avoid unrelated limits masking results.
10. **Logout last:** 204 empty body; confirm environment token cleared. Run Profile with revoked token (401). A separate device token remains valid.
11. Record date, environment, request names/statuses, assertions and any failures in your manual verification evidence. Automated tests and curl checks do **not** satisfy the manual Postman acceptance criterion.

Provider fallback and expiration scenarios are deterministically covered by existing tests. Do not trigger a live provider outage to manufacture evidence; mark manual cases requiring fixtures/configuration as pending if prerequisites are unavailable.

### Verification status

Automated verification during this handoff: 275 existing API tests passed (1,732 assertions). Both JSON files parse; all 22 registered endpoints have collection coverage; all 33 environment references resolve; request scripts compile and JSON body scripts handle quotes/backslashes. Twelve local HTTP smoke checks passed, covering public reads, guest recommendations/chatbot, missing/invalid authentication, and catalog validation. No live customer/order data was mutated by these HTTP checks.

A manual Postman import/run has **not** been performed by the coding agent; the developer must complete the sequence above in the Postman app. Postman/CLI were not found on PATH or in the checked standard installation locations. JSON/structural checks are not a Postman import or a full external-schema validation. No generator or validator dependency was installed.

## 10. Source mapping and known limitations

- Routing/access: `routes/api.php`, `AuthorizeApiChatbot`, `AuthorizeApiRecommendations`, `AppServiceProvider`, `FortifyServiceProvider`, `config/sanctum.php`, `bootstrap/app.php`.
- Fields/validation: `app/Http/Requests`, shared profile/password concerns, API AuthController.
- Response shapes: `app/Http/Resources/Api/V1`, `CatalogProductPresenter`, `CustomerOrderPresenter`, `BuildCartViewData`, `PrepareCheckout`.
- Domain rules: `CartService`, `OrderPlacementService`, `ResubmitPaymentProof`, `ProductCatalogRepository`, `RecommendationEngine`, shared chatbot services.
- Contract tests: `tests/Feature/ApiFoundationTest.php`, `MobileAuthenticationTest.php`, `MobileProfileTest.php`, `MobileCatalogTest.php`, `MobileBranchTest.php`, and `tests/Feature/Api/V1`.

Known implementation details are documented, not changed: registration's validated-but-unsaved address; different page validation between products/orders; stateless guest context is not per-device identity; private proof has no mobile download endpoint.

No mobile password reset/change, token refresh, customer order cancellation, admin operations, payment gateways, courier tracking, compatibility checking, or new endpoints are introduced by this handoff.
