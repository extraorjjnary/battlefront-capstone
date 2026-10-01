# Battlefront Capstone Development Context

## Project Title

**Integrated Web and Mobile Business Management System with Product Recommendation, Predictive Analytics, and Integrated Chatbot for Battlefront Computer Trading**

This document records the current Battlefront Computer Trading repository implementation, completed work, pending work, and development boundaries, verified on **2026-10-01** against repository files and Linear issue descriptions/statuses.

For current implementation facts, use the repository first, then Linear issue state and approved scope, existing handoff/context documents, and existing Postman/mobile API documentation. Planned capabilities are explicitly distinguished from implemented behavior.

This is a development reference, not an academic manuscript revision. `docs/capstone_manuscript.docx` is academically sensitive and read-only for agent work: do not modify, rename, format, convert, or regenerate it. The developer will revise it manually. This document makes no claim that the manuscript has already been synchronized with the implementation.

---

# 1. Project Overview

Battlefront Computer Trading is a computer hardware and peripherals retailer serving walk-in and online customers.

Existing business operations rely partly on manual or semi-manual processes such as handwritten records and basic spreadsheets for sales and inventory management. Customer inquiries are also handled manually through channels such as social media and phone communication.

These processes can contribute to:

- delayed inventory updates;
- stock mismatches;
- recording errors;
- slower customer inquiry handling;
- fragmented business information.

The Laravel system integrates Battlefront's major business processes and provides a shared backend for the web application and a separately maintained mobile client.

The system supports:

- customer and administrator accounts;
- product and category management;
- inventory monitoring and low-stock awareness;
- customer e-commerce activities;
- cart and order processing;
- sales monitoring;
- business reports;
- product recommendations;
- predictive sales/demand analytics (planned; implementation pending);
- chatbot-assisted customer inquiries;
- branch/store information.

The intelligent features are intended to support customer and managerial decisions, not replace human judgment.

Core catalog/inventory, customer commerce, administrator order/payment processing, sales reports, deterministic recommendations, chatbot, and mobile API implementation are complete. Real React Native + Expo consumer validation, predictive analytics, and the remaining integration/release work are pending; see Section 14.

---

# 2. Primary Study Location and Branch Scope

The primary operational branch is:

**Battlefront Computer Trading — Sagay City, Negros Occidental**

The Sagay City branch is the primary location for:

- requirements gathering;
- business-process observation;
- operational data;
- system testing;
- deployment;
- evaluation.

The repository also includes branch reference records for:

- Escalante City;
- San Carlos City;
- Guihulngan City;
- Bacolod City.

These additional branches are mainly represented through static store information such as location, address, contact information, and operating information supplied by the business.

All five seeded branches use the configured customer-facing operating-hours value **8:00 AM–6:00 PM**. This is static reference information in `config/battlefront.php`; there is no `operating_hours` column. Bacolod's inclusion reflects the current seeder/configuration and does not introduce another operational inventory branch.

## Current Branch Reference Data

| Branch          | Contact number                                   | Email                                | Location                                                                                                             |
| --------------- | ------------------------------------------------ | ------------------------------------ | -------------------------------------------------------------------------------------------------------------------- |
| Sagay City      | 0938 647 6046                                    | battlefrontcomputertrading@gmail.com | A, E Marañon St., Brgy. Poblacion II, Sagay City, Negros Occidental (beside LBC Sagay City), Sagay, Philippines 6122 |
| Escalante City  | Not yet confirmed                                | Not yet confirmed                    | Not yet confirmed; no official branch page is currently available                                                    |
| San Carlos City | Not listed on the available official branch page | Not yet confirmed                    | Carmona St., Brgy. V, San Carlos City, Negros Occidental, San Carlos City, Philippines 6127                          |
| Guihulngan City | 0947 946 5723                                    | battlefrontcomputertrading@gmail.com | L&E Arcade, Larena St., Brgy. Poblacion, Guihulngan City, Guihulngan, Philippines 6214                               |
| Bacolod City | 0961 176 4608 | battlefrontbacolod@gmail.com | Downtown, Along SKG Shopping Center, Beside Ukay-Ukayan 58 Lizares St. Brgy. 13, Bacolod CIty, Philippines, 6100 |

These reference values come from `database/seeders/BranchSeeder.php` and `config/battlefront.php`. Sagay City and Guihulngan City share the configured email address; Bacolod has a separate configured email. Unconfirmed values must remain null or undisplayed instead of being copied to other branches as verified facts.

Full operational detail such as live stock and order information is centered on the Sagay City branch.

The approved system is not a multi-branch operational synchronization platform.

---

# 3. Development Responsibility

## Primary Development Responsibility

The primary development responsibility for this project includes:

- Laravel backend;
- Vue 3 + Inertia.js web application;
- shared MySQL database;
- RESTful JSON API endpoints and contracts required by the React Native application;
- backend business logic shared by web and mobile clients.

## React Native + Expo Responsibility

Another group member is primarily responsible for:

- React Native UI;
- the separate React Native + Expo application implementation and runtime;
- mobile-side HTTP/API consumption.

The React Native + Expo application consumes Laravel's `/api/v1` JSON API and shares its MySQL-backed business data. Mobile UI code belongs in that separate project, not this repository. API completion does not establish that the real app has passed LAN integration testing.

**Laravel remains the shared source of backend business logic and persistent data for both web and mobile applications.**

---

# 4. Current System Architecture

The shared Laravel backend and Inertia web application follow a **monolithic deployment model** organized using a **three-tier logical architecture**. The mobile client is a separate consumer:

1. Presentation Layer
2. Application Layer
3. Data Layer

An external AI service is integrated specifically for chatbot response generation.

## 4.1 Presentation Layer — Web Application

Current web technologies:

- Laravel 13
- Inertia.js 3
- Vue 3
- Tailwind CSS 4
- Vite 8 through Vite+

The web application uses Inertia's server-driven architecture.

Normal request flow:

```text
Browser
    ↓
Laravel Web Route
    ↓
Laravel Controller
    ↓
Inertia Response
    ↓
Vue Page
```

The web application is **NOT a separate REST SPA**.

Normal Inertia web functionality should therefore use:

- Laravel web routes;
- Laravel controllers;
- Inertia responses;
- page props;
- Inertia forms;
- Wayfinder
- Inertia navigation/router features.

A separate REST endpoint or Axios-based frontend architecture should not be assumed for ordinary Inertia web features.

REST APIs remain appropriate where there is an actual API consumer or architectural requirement, particularly the React Native application.

## 4.2 Presentation Layer — Mobile Application

Separate mobile client technology:

- React Native + Expo

React Native communicates with Laravel through the implemented **RESTful JSON API at `/api/v1`**, using Sanctum bearer tokens for customer-specific operations.

Normal mobile request flow:

```text
React Native + Expo Application
    ↓ HTTP / JSON
Laravel /api/v1 Route
    ↓
Laravel Backend
    ↓
MySQL
```

The mobile application and Inertia web application use different communication mechanisms while sharing the same Laravel application and persistent business data.

```text
Web
Vue 3
   ↕ Inertia
Laravel
   ↕
MySQL

Mobile
React Native + Expo
   ↕ /api/v1 REST JSON API
Laravel
   ↕
MySQL
```

## 4.3 Application Layer

Laravel is responsible for the system's main application and business logic, including:

- authentication and users;
- products and categories;
- inventory;
- carts and checkout;
- orders;
- sales;
- reporting;
- product recommendation;
- predictive analytics (pending implementation);
- chatbot orchestration;
- REST API behavior required by the mobile application.

Business rules shared between web and mobile belong to the shared Laravel backend rather than being independently reimplemented by each client.

## 4.4 Data Layer

The approved relational database management system is **MySQL**.

The implemented database stores users, products/categories/tags, inventory, customer carts and orders, sales, branch reference information, chatbot knowledge, and framework infrastructure including personal access tokens. Forecast persistence remains planned; there is no forecast migration or model yet.

Current migrations and models establish the implemented schema. MySQL is the application backend; `phpunit.xml` uses SQLite in memory for automated tests. The starter `.env.example` still defaults to SQLite, so it does not describe the configured MySQL development backend.

## 4.5 External AI Integration

The chatbot agent is configured for **Google Gemini 3.5 Flash-Lite** (`gemini-3.5-flash-lite`) in `app/Ai/Agents/ChatbotResponseAgent.php`.

Laravel communicates with Gemini through the **Laravel AI SDK**, behind `ChatbotAiAdapter`. The configured timeout defaults to 20 seconds and accepts 5–30 seconds. Provider failures use the shared safe fallback; there is no automatic retry or alternate provider.

Gemini is used for generating the final natural-language chatbot response after the application has already categorized the inquiry and retrieved relevant system data.

Gemini is not the authoritative source for:

- stock quantities;
- product prices;
- order status;
- branch information;
- product recommendations.

System/database data remains authoritative for those facts.

---

# 5. Current Development Technologies and Tools

## Application Stack

- Laravel 13 / PHP (installed Laravel: 13.30.1 at this review)
- Vue 3 Composition API with plain JavaScript application components
- Inertia.js 3 (installed Laravel adapter: 3.3.2)
- Tailwind CSS 4
- Shadcn Vue
- Vite 8 / Vite+ (`vp` scripts)
- MySQL
- React Native + Expo (separate client project)
- Google Gemini 3.5 Flash-Lite, as configured in the chatbot agent
- Laravel AI SDK 0.11.2
- Fortify web authentication, Sanctum 4 mobile tokens, and Wayfinder route helpers

## Development and Testing Tools

- Laravel Herd — primary local Laravel/PHP server
- Visual Studio Code — primary IDE
- Git — version control
- GitHub — repository collaboration
- Composer — PHP dependency management
- npm — frontend dependency management
- Postman — manual REST API testing
- Pest/PHPUnit — Laravel automated unit and feature testing

Herd serves the Laravel application; `npm run dev` runs the Vite+ frontend development process and `npm run build` builds assets. Vite is not the mobile API server. `composer run dev` delegates to the project's Artisan development command.

Real mobile validation requires the correct Herd site to be reachable from the device on the same Wi-Fi/LAN. The existing EXT-63 preparation recorded a loopback-only Herd listener; actual device reachability remains unverified. Follow `MOBILE_INTEGRATION_VALIDATION.md` for LAN routing, device health checks, and the documented optional Artisan-server fallback. Do not treat a desktop-only `.test` hostname or phone localhost as a working mobile backend address.

---

# 6. Primary System Actors

The system has two primary actors:

1. Customer
2. Administrator

## Customer

Customers can:

- register, authenticate, and manage their profile;
- browse, search, filter, and view product details;
- receive rule-based product recommendations;
- manage a shopping cart;
- place orders and select an available payment method;
- monitor order status and view order history;
- use chatbot assistance;
- view Battlefront branch/store information.

## Administrator

Administrators can:

- manage products and categories;
- manage inventory and monitor stock levels;
- process and manage customer orders;
- monitor sales and revenue;
- view sales reports and dashboard summaries;
- view customer accounts and their order information;
- manage chatbot knowledge;
- view branch reference information;
- monitor sales, orders, and inventory through the administrative dashboard.

Forecast generation/review is planned for administrators but is not implemented. Current customer administration exposes index/detail viewing, not account editing. Branch reference data is maintained through seeders/configuration; there is no branch-management CRUD interface.

## Authorization Boundaries

- Public registration creates customers; request input cannot grant administrator privileges.
- Fortify handles web session authentication. Mandatory email verification is disabled; the retained `email_verified_at` field does not imply a verification gate.
- Administrator web routes require authentication and `access-administration`. Customer cart, checkout, and order routes require the customer role and enforce resource ownership.
- Guests can browse products/branches and use public recommendations/chatbot. Administrators cannot use customer commerce, recommendations, or the storefront chatbot.
- The mobile API is customer-facing only. Protected operations require Sanctum bearer authentication plus the customer gate; browser sessions do not authenticate these API calls. Tokens expire after 30 days, and logout revokes only the presented token.
- Optional-auth chatbot/recommendation endpoints accept guests and customers, reject invalid supplied credentials with 401, and reject valid administrator tokens with 403. Foreign customer cart/order resources are not exposed.
- Administrative inventory, payment verification, reports, and knowledge management remain web-only; there are no mobile administrator endpoints.

---

# 7. Core Functional Areas

## Core Business Management

- Authentication and customer accounts
- Product management
- Category management
- Inventory management
- Low-stock monitoring
- Customer profile management and administrator customer viewing
- Branch/store information

## E-Commerce

- Product catalog
- Product search and filtering
- Shopping cart
- Checkout
- Order placement
- Order status management
- Order history
- Payment-method selection

## Business Monitoring

- Sales recording and monitoring
- Revenue information
- Business reports
- Administrative dashboard

## Intelligent Modules

- Product Recommendation — complete
- Predictive Analytics — pending
- Integrated Chatbot — complete

## Integration

- Inertia-based web application
- React Native REST API — complete; actual consumer validation pending
- Gemini chatbot integration

## Current Catalog and Inventory Rules

- Customer-visible products must be active and belong to an active category. Deactivation retains history and supports reactivation. Inactive products/categories are excluded from browsing, purchase eligibility, and recommendations.
- Products have required unique product codes, nullable brands, tags, regular/optional discount prices, and managed image paths. Existing catalog import/image tools support catalog preparation; they are not historical-sales import or forecasting features.
- Live inventory is Sagay-only, with one inventory record per product rather than per-branch stock. Missing or zero stock prevents purchasing and recommendations; out-of-stock products can still appear in the active catalog.
- Quantities cannot be negative. Low stock means positive quantity strictly below `reorder_level`; zero stock is a separate out-of-stock state. Customer API catalog responses expose availability status rather than exact quantities.
- Cart operations use current server prices and stock eligibility. Adding to a cart does not reserve or deduct stock; checkout revalidates the entire cart.

## Current Order, Payment, and Sales Rules

- Pickup accepts `cash`, `card_at_store`, `gcash`, and `maya`. Delivery accepts only GCash/Maya and requires a delivery address; pickup prohibits a nonempty delivery address.
- GCash/Maya require an uploaded JPEG/JPG, PNG, or WebP proof image of at most 5 MB. Cash/card-at-store prohibit proof uploads. Proof is private and accessible through authorized administrator routes, never public storage URLs or mobile proof downloads.
- `OrderPlacementService` locks and rechecks customer/cart, catalog, and stock data. It snapshots recipient/contact/fulfillment, item quantities and current effective prices, creates a pending order/payment, deducts stock, and consumes the cart in one transaction. Failed placement rolls back database changes and cleans up newly stored proof.
- Client totals and prices are not authoritative. Later payment verification or processing must not deduct stock again. The API has no idempotency-key contract; after an uncertain placement response, inspect order history before retrying.
- Administrator transitions are `pending -> processing/cancelled` and `processing -> completed/cancelled`; completed/cancelled are terminal. Both processing and completion require verified payment.
- Payment decisions are manual: pending becomes verified or rejected. Wallet verification requires accessible evidence and the administrator's payment-account/platform cross-check; uploading proof alone never verifies payment. Configured demo payment accounts must remain clearly identified as demo data.
- Rejected wallet proof can be replaced by the owning customer while the order is nonterminal. Replacement resets payment to pending and clears rejection feedback without changing order status or stock.
- Explicit administrator cancellation restores purchased quantities atomically with the eligible status transition, without double-restocking on retries. Payment rejection alone neither cancels an order nor restores stock. Customers have no cancellation/status/payment-verification endpoint.
- Completing an order records its sale once using the order total and completion date. Existing dashboards/reports summarize recorded sales; they are not forecasts. Order item prices/quantities remain snapshots, while displayed product names/brands/images come from current product records.

Shared actions/services implement these rules for both Inertia and mobile API controllers.

---

# 8. Product Recommendation Module

The Product Recommendation Module uses **deterministic rule-based filtering**.

**Status: complete.** EXT-35 and its criteria, engine, customer workflow, and test issues are Done; EXT-64 adds the completed mobile endpoints. Web and mobile use the same `RecommendationEngine` and eligible catalog query.

It is intentionally **not** collaborative filtering, machine learning, deep learning, or generative-AI recommendation.

The design works without historical user-item interaction data and does not depend on a learned recommendation model.

## Recommendation Inputs

- customer budget;
- intended use;
- preferred brand;
- selected product category;
- preferred product tags;
- available Sagay inventory.

```text
Customer Requirements
        ↓
Rule-Based Criteria
        ↓
Match Against Product Attributes
        ↓
Consider Available Catalog / Inventory
        ↓
Suitable Product Results
```

Explicit PC-part compatibility checking and configurator behavior are outside the current capstone scope. The recommendation module does not use a dedicated product-to-product compatibility relationship or a `product_compatibilities` table.

Budget and intended use are required. Category, brand, and preferred tags are optional. Eligibility requires an active product/category, positive Sagay stock, an effective price within budget, an intended-use category/tag signal, and any explicit category/brand restrictions. Effective price is the discount price when present, otherwise the regular price. Brand matching is trimmed and case-insensitive; null-brand products remain eligible when no brand preference is supplied.

Results rank by intended-use match count descending, preferred-tag match count descending, effective price ascending, then product ID ascending. Preferred tags affect ranking rather than requiring every selected tag. Results include match reasons; no match is an explicit empty result, not a fabricated recommendation. Recommendations are not persisted.

The module operates using Battlefront's internal product catalog and inventory data.

The **Product Recommendation Module**, not the chatbot, owns recommendation logic.

---

# 9. Predictive Analytics Module

**Status: pending.** EXT-36 and EXT-40/42–46 are Backlog. Existing sales recording and reporting are implemented prerequisites, but quarterly aggregation, forecasting calculations, forecast persistence, and administrator forecasting pages are not implemented.

The planned module will use historical Battlefront sales and inventory inputs to estimate future product demand and identify sales trends.

The approved approach is intentionally lightweight and non-machine-learning.

## Historical Data Development Plan

EXT-40 plans repeatable **synthetic historical sales data** spanning enough quarters to exercise known aggregate and forecast outcomes. It must be distinguishable from operational Battlefront records and must not be presented as real client sales or evaluation evidence. Existing catalog/demo seeders do not establish that this historical dataset exists.

If real historical client data is supplied later, it should be validated and imported/replaced into the forecasting data basis through an explicit, reviewed process. Do not silently mix synthetic records with operational history. No historical client dataset or completed historical-sales import pipeline is established by the current implementation.

## Data Basis

- product;
- category;
- price;
- quantity sold;
- stock level;
- transaction date.

Historical data will be organized using **calendar-quarter aggregation**, with explicit product/category filters, date boundaries, and empty-quarter handling.

## Approved Forecasting Techniques

1. Quarterly historical aggregation (EXT-42)
2. Moving-average forecasting (EXT-43)
3. Least-squares linear trend forecasting (EXT-44)
4. Forecast persistence (EXT-45)
5. Administrator generation/review through Inertia (EXT-46)

```text
Historical Sales / Inventory Data
        ↓
Aggregate by Quarter
        ↓
Moving Average / Least-Squares Linear Trend
        ↓
Estimated Future Product Demand
```

### Moving Average

Moving average uses recent quarterly demand to smooth short-term fluctuations and produce a historical demand baseline.

### Linear Trend Analysis

Least-squares linear regression is used to identify the historical sales trend and project demand for an upcoming quarter.

The resulting forecasts are decision-support information for inventory and sales planning rather than guaranteed predictions.

Persistence is planned to distinguish `moving_average` and `linear_trend` results and record the product, period, result, and required generation context. Administrators will generate/review results with source-period context and clear empty/insufficient-data states; customers will not access forecasting administration.

The moving-average window, missing-history policies, numeric edge cases, and forecast rerun/replace/version rule must be made explicit in their implementation issues. They are not settled by an existing forecasting implementation. Do not add machine-learning forecasting, external market datasets, or external market/economic inputs.

---

# 10. Chatbot Module

The chatbot uses a **hybrid deterministic + generative architecture**.

**Status: complete.** EXT-47 and its knowledge-management, routing, resolver, provider, orchestration, interface, and testing issues are Done. EXT-61 provides the completed mobile endpoint. Web and mobile share the same backend pipeline.

```text
Customer Query
        ↓
Deterministic Query Categorization
        ↓
Retrieve Relevant System / Database Context
        ↓
Build Context for the Query
        ↓
Configured Gemini 3.5 Flash-Lite
        ↓
Natural-Language Response
```

## Supported Query Categories

- product;
- order;
- store;
- FAQ.

### Deterministic Stage

The application first identifies the query category using predefined rules.

Relevant information is then retrieved from system data or chatbot knowledge.

### Generative Stage

The retrieved information is combined with the customer's query and provided as context to Gemini.

Gemini produces the final natural-language response.

```text
Categorization = deterministic
Data retrieval = application/database controlled
Final wording = generative when safe context/provider are available; otherwise grounded fallback
```

Unsupported inquiries use predefined fallback behavior.

## Implemented Privacy and Scope Controls

- Normalize and route deterministically, clarify ambiguous/multiple topics, and retrieve only the selected category's authoritative context before calling the AI adapter.
- Public product/store/FAQ inquiries work for guests. Personal order inquiries require a customer identity and recheck ownership; foreign/missing orders share a safe fallback. Only Sagay live inventory is available.
- Product, price, inventory, order, and branch facts come from live application data. Active approved knowledge supplies FAQ content and must not override live facts. Sensitive input and absent/unsupported context are handled before a provider call.
- Provider timeout/failure/empty output uses existing resolver facts and approved fallback wording without inventing business facts. No automatic retries or alternate AI provider are enabled.
- Follow-ups use an encrypted 15-minute context token holding references/clarification state, not a persistent chat transcript. Web context is session/user-bound; mobile customer context is account/bearer-token-bound. Guest API context continues public topics only and supplies no identity. Reset current-chat memory on New chat or identity/token changes, and requery live facts each turn.
- Rate limits are five guest questions per minute by IP and ten customer questions per minute by customer identity; the API also has its global limiter.
- Open-domain chat, complex complaint resolution, compatibility advice, and chatbot-driven recommendations are outside scope. Do not invent warranty/refund/repair or other business policies absent approved context.

The chatbot does not perform the Product Recommendation Module's recommendation logic.

---

# 11. Current Database Baseline and Planned Forecasting

Current migrations/models implement the following core entities:

- Users
- Branches
- Categories
- Products
- Tags
- Product Tag
- Inventory
- Carts
- Cart Items
- Orders
- Order Items
- Sales
- Chatbot Knowledge

Framework infrastructure includes sessions, password reset tokens, cache/jobs, and Sanctum personal access tokens. **Forecasts are planned, not an existing table/model.** The current sales/order data provides a foundation for future forecasting.

## Relationship Baseline

Implemented relationships include:

- Categories and Products;
- Products and Tags through Product Tag;
- Products and Inventory;
- Users and Carts;
- Users and Orders;
- Carts and Cart Items;
- Cart Items and Products;
- Orders and Order Items;
- Order Items and Products;
- Orders and Sales.

Inventory is unique per product, carts are unique per customer, and sales are unique per order. Branches are reference records: `2026_09_26_151046_remove_branch_id_from_users_table.php` removes the former user/branch foreign key. Do not infer current user-branch membership or per-branch stock from older context.

Migrations and model definitions establish the actual foreign keys, columns, constraints, and relationships. Do not infer additional relationships merely because they are common in e-commerce applications. The planned Product/Forecast relationship remains part of future forecast persistence work.

## Retained Decisions and Subsequent Implementation

EXT-6 is Done. Its recorded baseline decisions remain useful historical context, but subsequent migrations and completed features supersede stale statements about the current schema or deferred work. This section describes implementation without claiming to revise or synchronize the manuscript:

- use Laravel-compatible unsigned big integer primary and foreign keys rather than interpreting the ERD's generic `int` labels as a required physical storage size;
- retain Laravel authentication infrastructure fields and tables required by the installed authentication features, including `email_verified_at`, `remember_token`, conventional timestamps, password reset tokens, and sessions;
- retain the planned `forecasts.method` distinction for moving-average and least-squares linear-trend results; forecast persistence is still pending;
- treat Sagay City as the sole operational branch for live sales and inventory, selected through application configuration; the current schema has no `branches.is_primary`;
- retain the implemented `is_active` boolean with a default value of `true` on categories, products, and chatbot knowledge; use neither a lifecycle status enum nor Laravel soft deletes for these records;
- use `DECIMAL(12,2)` for monetary values, matching unsigned integer types for quantities, and explicit foreign-key, uniqueness, and non-negative-value constraints where required by the approved relationships and business rules;
- use `customer` and `administrator` roles; `pending`, `processing`, `completed`, and `cancelled` order states; `pending`, `verified`, and `rejected` payment states; and `product`, `order`, `store`, and `faq` knowledge categories. Chatbot routing also supports an unsupported outcome. Forecast method values `moving_average` and `linear_trend` remain planned.
- expose **8:00 AM–6:00 PM** for the seeded reference branches through application configuration, without an `operating_hours` column.
- seed confirmed branch addresses and contact numbers into the existing reference fields; keep unavailable values null and do not invent placeholders.
- keep branch email reference data in application configuration; the current schema has no `branches.email` column.
- keep deactivated categories, products, and chatbot knowledge in the database for historical and administrative reference, allow administrators to reactivate them, and prohibit physical deletion when historically referenced;
- exclude inactive products from the customer catalog, cart eligibility, and recommendation results; exclude inactive categories and their products from customer browsing; and exclude inactive chatbot knowledge from chatbot retrieval;
- keep recommendation behavior rule-based using budget, intended use, preferred brand, category, product preferences or tags, and Sagay inventory; do not implement a `product_compatibilities` table, product-to-product compatibility relationship, or dedicated compatibility-checking/configurator feature.

Subsequent implemented schema/commerce decisions include:

- orders snapshot required recipient name, contact number, and fulfillment method; delivery address is nullable in persistence, remains null for pickup, and is required by delivery checkout validation;
- fulfillment methods are `pickup` and `delivery`;
- manual payment methods are `cash`, `card_at_store`, `gcash`, and `maya`, stored in the string-backed `orders.payment_method` column;
- the proof column is nullable for non-wallet orders, while checkout requires private proof for GCash/Maya and prohibits it for cash/card-at-store;
- rejected wallet payments carry customer-facing rejection feedback and allow eligible proof replacement;
- users have profile delivery-address and appearance fields; products have unique product codes, nullable brands, import tracking, and `image_path` rather than the former image URL field;
- checkout validation, transactional placement, manual payment decisions, initial stock deduction, explicit cancellation restoration, and completed-order sales recording are implemented, as described in Section 7.

---

# 12. Approved Scope Boundaries

## Branch Operations

Outside scope:

- multi-branch inventory synchronization;
- branch-specific operational customization.

Sagay City remains the primary operational branch.

Escalante City, San Carlos City, Guihulngan City, and Bacolod City are customer-reference locations containing static branch/store information in the repository.

The customer-facing operating-hours value for each listed branch is **8:00 AM–6:00 PM** and remains reference information rather than branch-specific operational customization.

## Payments

The system supports:

- payment-method selection;
- cash, card-at-store, GCash, and Maya as manually processed payment methods;
- private payment-proof evidence for GCash and Maya;
- manual payment verification procedures determined by Battlefront.

Outside scope:

- real-time online payment gateway integration;
- automated payment verification;
- credit-card gateway processing;
- third-party e-wallet gateway integration.

## Delivery and Logistics

The system supports:

- order placement;
- order monitoring;
- order status tracking.
- pickup and delivery fulfillment, with recipient and contact snapshots on the order.

Actual delivery continues through Battlefront's existing business processes.

Outside scope:

- courier API integration;
- logistics management;
- route optimization;
- real-time delivery tracking.

## Recommendation Data

The recommendation engine uses internal Battlefront catalog and inventory information.

Outside scope:

- external supplier pricing;
- external supplier availability;
- competitor pricing;
- external market product availability;
- explicit PC-part compatibility checking or configurator behavior;
- a dedicated product-to-product compatibility data model.

## Forecasting Data

Forecasting is planned around Battlefront's historical sales and inventory records. Clearly identified synthetic history will support development first; validated real client history should replace/import later if supplied. Synthetic development data is not an external market dataset and must not be presented as operational client history.

Outside scope:

- external forecasting datasets;
- external market trends;
- external economic indicators;
- supplier-disruption inputs;
- other external market intelligence.

## Chatbot Behavior

Supported:

- product inquiries;
- order inquiries/status;
- store information;
- FAQs.

Outside scope:

- unrestricted open-domain conversation;
- automated complex complaint resolution;
- chatbot-driven product recommendation.

Product recommendations remain the responsibility of the Product Recommendation Module.

---

# 13. Development Methodology

The study follows a **descriptive-developmental research design**.

The software development methodology is **Agile SDLC**, selected because the system contains multiple interacting components that benefit from iterative development, testing, stakeholder feedback, and refinement.

```text
Requirements
    ↓
Design
    ↓
Development
    ↓
Testing
    ↓
Deployment
    ↓
Review
```

Development is intended to be iterative and incremental rather than one large single-stage implementation.

## Current Development Workflow

Follow **Understand → Plan → Implement → Test → Explain → Human Review → Complete** for non-trivial development. Read applicable `AGENTS.md`, `.ai/rules`, relevant skills, current implementation, and the approved issue before changing behavior. Confirm installed package versions and use version-appropriate documentation when APIs matter.

Keep changes focused on the approved issue, reuse existing components/actions/services, and preserve shared web/API business rules. Vue application work uses Composition API and plain JavaScript, existing Inertia patterns, and Wayfinder routes. Do not introduce dependencies, architectural changes, additional frameworks, or a mobile UI in this repository without explicit approval.

Run the narrowest relevant automated checks for code changes; apply Pint to changed PHP and the applicable static-analysis/build checks. Keep frontend formatting focused and avoid cosmetic-only diffs. Explain behavior, validation, and unresolved limitations for human review. Passing tests do not constitute human approval or authorize marking a Linear issue Done. Do not modify Linear issues/comments or create commits without authorization.

Documentation-only updates require source/diff review rather than invented feature tests. Never modify or transform the manuscript as a side effect of code or context maintenance; academic revisions remain the developer's responsibility.

---

# 14. Current Delivery Status and Remaining Work

Status snapshot verified against Linear on **2026-10-01**:

| Area | State | Evidence / remaining boundary |
| --- | --- | --- |
| Core catalog, inventory, roles, and branch reference | Complete | Implemented routes/models/services and completed core issues |
| Cart, checkout, orders, manual payments, cancellation restoration, sales/reports | Complete | EXT-22–27, EXT-28–34, EXT-80–83 Done |
| Deterministic recommendations | Complete | EXT-35, EXT-37–39, EXT-41, EXT-64 Done |
| Integrated chatbot | Complete | EXT-47–55 and EXT-61 Done |
| Mobile API foundation/customer endpoints | Complete | EXT-56–61 and EXT-64 Done |
| Mobile handoff and Postman collection | Complete | EXT-62 Done; developer-reported successful manual Postman Desktop verification |
| Actual React Native + Expo integration | Pending | EXT-63 Backlog; preparation complete, real LAN consumer journeys not run |
| Predictive analytics and historical development data | Pending | EXT-36, EXT-40, EXT-42–46 Backlog |
| Cross-module integration and release-quality checks | Pending | EXT-65–72 Backlog |
| Deployment, pilot evaluation, and release candidate | Pending | EXT-73–79 Backlog |

## Completed Mobile API Scope

`routes/api.php` defines 22 `/api/v1` endpoints covering health, customer registration/login/logout, profile read/update, catalog search/filter/detail, branch information, cart operations, checkout preview, order placement/history/detail, rejected-proof replacement, chatbot, and recommendation options/results.

Controllers reuse shared business logic and safe resource presenters. Responses use the documented JSON envelopes, pagination, validation/access errors, and rate limits. The global API limit is 60 requests per minute per IP, with additional authentication/chatbot limits. No mobile administrator operations, token refresh, password-reset/change, customer cancellation, payment gateway, or courier endpoints are present.

Supporting artifacts:

- [Mobile API handoff](MOBILE_API_HANDOFF.md)
- [Postman collection](Battlefront_API_v1.postman_collection.json)
- [Postman environment placeholders](Battlefront_API_v1.postman_environment.json)
- [React Native + Expo validation plan and evidence gaps](MOBILE_INTEGRATION_VALIDATION.md)

Known contract limitations remain explicit: registration validates but does not persist the delivery address (profile update does); private proof has no mobile download endpoint; guest chat context is not device identity; order placement has no idempotency-key contract. This context update does not change those behaviors.

## EXT-62 and EXT-63 Completion Boundary

EXT-62 is complete. The developer reported successful manual Postman Desktop testing and approved it; the handoff does not contain a dated run export/screenshots. Existing preparation records 275 API tests passing with 1,732 assertions. These are recorded prior results, not a fresh test run performed for this context update.

EXT-63 remains pending real **React Native + Expo** validation with the responsible group member, preferably on a physical phone on the same Wi-Fi/LAN as the backend laptop. Confirm the correct Herd site is reachable, exercise device and native-client `/api/v1/health`, then run the documented customer journeys, image/link access, uploads, access failures, and logout behavior. Record app/backend revisions, Expo runtime, device, and redacted results. Current preparation records all RN-01–RN-23 journeys as not run and has established no API-contract defect.

Postman, desktop health, or automated backend success cannot close EXT-63. Classify observed mismatches as backend, client, environment, or contract clarification; keep fixes focused and Laravel-owned unless responsibility is explicitly reassigned. Consumer retest evidence and human review remain required.

## Remaining Integration, Testing, and Release Work

- EXT-65/66/68: verify the complete customer web journey, web/API rule consistency, and administrator operational journey, including forecasting when available.
- EXT-67/69/70: security/data-privacy review, database/query-performance review, compatibility/responsiveness and manual API testing.
- EXT-71/72: full release-quality verification and focused integration-defect resolution.
- EXT-73–76: production configuration/operations planning, controlled production data initialization, release evidence, and Sagay deployment/smoke testing.
- EXT-77–79: Sagay pilot UAT/capstone evaluation, findings triage, and an evaluated release candidate.

Completed feature tests do not mean these broader acceptance gates are complete. Follow issue dependencies and acceptance criteria; this roadmap does not authorize implementing multiple modules or expanding scope at once.

---

# 15. Testing and Evaluation Context

Testing is part of the approved Agile development process.

Laravel's built-in testing framework using **Pest/PHPUnit** is used for automated unit and feature testing of important application and business logic.

Relevant implementation areas include:

- authentication and authorization;
- product/category behavior;
- inventory management;
- cart and checkout behavior;
- order processing;
- sales behavior;
- recommendation rules;
- forecasting calculations (future coverage with the pending module);
- deterministic chatbot categorization;
- REST API behavior required by React Native.

Postman is used for manual REST API endpoint testing where appropriate.

Automated tests use SQLite in memory; MySQL remains the development backend and intended deployment database. Treat MySQL/database review, full integration testing, actual mobile consumer evidence, and release acceptance as separate checks. See Section 14 for existing verification evidence and pending gates.

## Capstone Evaluation

The following evaluation framework is retained from the existing project context. Formal evaluation remains pending; manuscript wording was not reverified during this update.

### McCall's Software Quality Model

Used to evaluate software quality, including the manuscript's identified quality criteria under:

- product operation;
- product revision;
- product transition.

### Computer System Usability Questionnaire (CSUQ)

Used for customer usability evaluation.

The manuscript also assigns usability evaluation through McCall's model for the appropriate IT-expert and Battlefront-personnel evaluators.

Implementation and testing should therefore preserve measurable correctness, usability, maintainability, and system-quality characteristics needed during formal evaluation.

---

# 16. Data Privacy Context

Customer and transaction information must be handled in accordance with the **Philippine Data Privacy Act of 2012**.

Relevant system areas include:

- customer accounts;
- transactions;
- orders;
- API responses;
- application data;
- chatbot context.

Only information required for the intended system function should be exposed to users, clients, or external services.

---

# 17. Project Decision Boundaries

### Approved Scope

Directly represented by the approved manuscript.

### Necessary Implementation Detail

A technical implementation decision required to deliver an approved capability without changing its intended scope or architecture.

### Optional Enhancement

A potentially useful improvement that is not necessary for the approved system.

### Scope Extension

A capability that adds behavior beyond the approved manuscript.

Features categorized as scope extensions should not be treated as existing project requirements.

---

# 18. Source of Truth

## Current Repository State

For maintaining this development context, use this source order:

1. Current repository implementation: routes, controllers, shared actions/services, models, migrations, configuration, dependency versions, and tests.
2. Linear issue state, explicit acceptance criteria, and approved scope/implementation decisions.
3. Existing handoff and context documents.
4. Existing Postman/mobile API documentation and artifacts.

Implementation establishes what currently exists; approved issue scope establishes what remains intended. Do not describe planned work as implemented, or treat a historical document's stale statement as authority over current code. Do not silently infer completion from tests or a prepared checklist.

## Academic Scope and Manuscript Handling

The approved Chapters 1–3 manuscript and adviser-approved amendments remain academic scope references. This development snapshot neither amends them nor claims to have verified their current wording. Any known scope conflict must be surfaced for human review rather than used to silently expand the project.

`docs/capstone_manuscript.docx` must remain untouched by this workflow. The developer will manually revise it later. Do not rename, format, convert, regenerate, or otherwise modify the manuscript, and do not propagate manuscript-derived formatting changes elsewhere unless already reflected in the codebase or approved project documentation.

## Evidence to Obtain Later

- Real React Native + Expo LAN run evidence and resolution of Herd/device reachability prerequisites (EXT-63).
- Repeatable synthetic forecasting history, explicit calculation/persistence policies, and real historical client data if later supplied (EXT-40/42–46).
- Integration, production readiness, pilot evaluation, and release acceptance evidence (EXT-65–79).

This context update does not supply that evidence or close those pending issues.
