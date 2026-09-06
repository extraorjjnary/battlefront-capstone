# Battlefront Capstone Development Context

## Project Title

**Integrated Web and Mobile Business Management System with Product Recommendation, Predictive Analytics, and Integrated Chatbot for Battlefront Computer Trading**

This document is the development-oriented reference for the approved Battlefront Computer Trading capstone project.

It summarizes the architectural, functional, database, algorithmic, technological, scope, and methodological decisions defined in the approved Chapters 1–3 manuscript.

It is not a replacement for the manuscript.

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

The proposed system integrates Battlefront's major business processes into one web-and-mobile business management platform.

The system supports:

- customer and administrator accounts;
- product and category management;
- inventory monitoring and low-stock awareness;
- customer e-commerce activities;
- cart and order processing;
- sales monitoring;
- business reports;
- product recommendations;
- predictive sales/demand analytics;
- chatbot-assisted customer inquiries;
- branch/store information.

The intelligent features are intended to support customer and managerial decisions, not replace human judgment.

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

Battlefront also operates branches in:

- Escalante City;
- San Carlos City;
- Guihulngan City.

These additional branches are mainly represented through static store information such as location, address, contact information, and operating information supplied by the business.

All four branches use the confirmed customer-facing operating-hours value **8:00 AM–6:00 PM**, based on the shared operating hours published in the business's Facebook bio. This is static reference information selected through application configuration; it does not authorize an `operating_hours` column outside the current manuscript ERD.

## Current Branch Reference Data

| Branch | Contact number | Email | Location |
| --- | --- | --- | --- |
| Sagay City | 0938 647 6046 | battlefrontcomputertrading@gmail.com | A, E Marañon St., Brgy. Poblacion II, Sagay City, Negros Occidental (beside LBC Sagay City), Sagay, Philippines 6122 |
| Escalante City | Not yet confirmed | Not yet confirmed | Not yet confirmed; no official branch page is currently available |
| San Carlos City | Not listed on the available official branch page | Not yet confirmed | Carmona St., Brgy. V, San Carlos City, Negros Occidental, San Carlos City, Philippines 6127 |
| Guihulngan City | 0947 946 5723 | battlefrontcomputertrading@gmail.com | L&E Arcade, Larena St., Brgy. Poblacion, Guihulngan City, Guihulngan, Philippines 6214 |

The same email address appears to be shared across branches, but it is currently confirmed only for Sagay City and Guihulngan City. Unconfirmed values must remain null or undisplayed instead of being copied to other branches as verified facts.

Full operational detail such as live stock and order information is centered on the Sagay City branch.

The approved system is not a multi-branch operational synchronization platform.

---

# 3. Development Responsibility

## Primary Development Responsibility

The primary development responsibility for this project includes:

- Laravel backend;
- Vue 3 + Inertia.js web application;
- shared MySQL database;
- RESTful JSON API endpoints and contracts required by the Flutter application;
- backend business logic shared by web and mobile clients.

## Flutter Responsibility

Another group member is primarily responsible for:

- Flutter UI;
- Flutter application implementation;
- mobile-side HTTP/API consumption.

The Flutter application still depends on the shared Laravel backend and MySQL data.

**Laravel remains the shared source of backend business logic and persistent data for both web and mobile applications.**

---

# 4. Approved System Architecture

The system follows a **monolithic deployment model** organized using a **three-tier logical architecture**:

1. Presentation Layer
2. Application Layer
3. Data Layer

An external AI service is integrated specifically for chatbot response generation.

## 4.1 Presentation Layer — Web Application

Approved web technologies:

- Laravel
- Inertia.js
- Vue 3
- Tailwind CSS
- Vite

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

REST APIs remain appropriate where there is an actual API consumer or architectural requirement, particularly the Flutter application.

## 4.2 Presentation Layer — Mobile Application

Approved mobile technology:

- Flutter

Flutter communicates with Laravel through a dedicated **RESTful JSON API**.

Normal mobile request flow:

```text
Flutter Application
    ↓ HTTP / JSON
Laravel API Route
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
Flutter
   ↕ REST JSON API
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
- predictive analytics;
- chatbot orchestration;
- REST API behavior required by the mobile application.

Business rules shared between web and mobile belong to the shared Laravel backend rather than being independently reimplemented by each client.

## 4.4 Data Layer

The approved relational database management system is **MySQL**.

The database stores persistent data for users, products and categories, inventory, customer carts and orders, sales, forecasts, branch information, chatbot knowledge, and other entities represented in the approved ERD.

The approved ERD and normalized relational design are authoritative for the database baseline.

## 4.5 External AI Integration

The chatbot integrates **Google Gemini 2.5 Flash API**.

Laravel communicates with Gemini through the **Laravel AI SDK**.

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

- Laravel / PHP
- Vue 3
- Inertia.js
- Tailwind CSS
- Shadcn Vue
- Vite
- MySQL
- Flutter
- Google Gemini 2.5 Flash API
- Laravel AI SDK

## Development and Testing Tools

- Laravel Herd — local Laravel/PHP development environment
- Visual Studio Code — primary IDE
- Git — version control
- GitHub — repository collaboration
- Composer — PHP dependency management
- npm — frontend dependency management
- Postman — manual REST API testing
- Pest/PHPUnit — Laravel automated unit and feature testing

Flutter remains part of the approved overall architecture even though its application implementation is primarily handled by another group member.

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
- generate business reports;
- manage customer accounts;
- manage chatbot knowledge;
- manage or view branch information;
- generate and view forecasting results;
- monitor system activity through the administrative dashboard.

The mobile application is primarily customer-facing.

Administrative workflows involving complex data entry, bulk inventory operations, or detailed business reports are primarily intended for the web platform.

---

# 7. Core Functional Areas

## Core Business Management

- Authentication and customer accounts
- Product management
- Category management
- Inventory management
- Low-stock monitoring
- Customer account management
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

- Product Recommendation
- Predictive Analytics
- Integrated Chatbot

## Integration

- Inertia-based web application
- Flutter REST API
- Gemini chatbot integration

---

# 8. Product Recommendation Module

The Product Recommendation Module uses **deterministic rule-based filtering**.

It is intentionally **not** collaborative filtering, machine learning, deep learning, or generative-AI recommendation.

The design was selected because the newly deployed system does not initially have enough historical user-item interaction data to justify collaborative filtering or a machine-learning recommendation model.

## Recommendation Inputs

- customer budget;
- intended use;
- preferred brand;
- selected product category;
- customer/product preferences;
- product tags;
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

The current Chapters 1–3 manuscript source has already been revised to remove compatibility-based recommendation criteria and any promise of explicit compatibility checking. Its remaining references to product-compatibility inquiries in the Chapter I Introduction and Chapter III Research Locale describe Battlefront's existing staff-assisted business context rather than a system feature. No further manuscript revision is required for this scope decision unless the adviser requests additional clarification. The manuscript remains the authoritative scope source, with this developer-approved implementation decision recorded transparently.

The module operates using Battlefront's internal product catalog and inventory data.

The **Product Recommendation Module**, not the chatbot, owns recommendation logic.

---

# 9. Predictive Analytics Module

The Predictive Analytics Module uses historical Battlefront sales and inventory data to estimate future product demand and identify sales trends.

The approved approach is intentionally lightweight and non-machine-learning.

## Data Basis

- product;
- category;
- price;
- quantity sold;
- stock level;
- transaction date.

Historical data is organized using **quarterly aggregation**.

## Approved Forecasting Techniques

1. Historical Data Aggregation
2. Moving Average
3. Trend Analysis
4. Least-Squares Linear Regression

```text
Historical Sales / Inventory Data
        ↓
Aggregate by Quarter
        ↓
Moving Average
        ↓
Trend / Linear Regression Analysis
        ↓
Estimated Future Product Demand
```

### Moving Average

Moving average uses recent quarterly demand to smooth short-term fluctuations and produce a historical demand baseline.

### Linear Trend Analysis

Least-squares linear regression is used to identify the historical sales trend and project demand for an upcoming quarter.

The resulting forecasts are decision-support information for inventory and sales planning rather than guaranteed predictions.

---

# 10. Chatbot Module

The chatbot uses a **hybrid deterministic + generative architecture**.

```text
Customer Query
        ↓
Deterministic Query Categorization
        ↓
Retrieve Relevant System / Database Context
        ↓
Build Context for the Query
        ↓
Google Gemini 2.5 Flash
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
Final wording = generative
```

Unsupported inquiries use predefined fallback behavior.

The chatbot does not perform the Product Recommendation Module's recommendation logic.

---

# 11. Approved Database Baseline

The manuscript ERD defines a normalized relational database containing the following core entities:

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
- Forecasts
- Chatbot Knowledge

These entities support the approved business processes including product and inventory management, ordering, sales monitoring, recommendations, forecasting, and chatbot knowledge.

## Relationship Baseline

Relationships represented by the approved database design include associations among:

- Categories and Products;
- Products and Tags through Product Tag;
- Products and Inventory;
- Users and Carts;
- Users and Orders;
- Carts and Cart Items;
- Cart Items and Products;
- Orders and Order Items;
- Order Items and Products;
- Orders and Sales;
- Products and Forecasts;
- Branches and applicable user/branch data.

The **manuscript ERD remains authoritative** for:

- exact cardinalities;
- foreign keys;
- optional relationships;
- column definitions;
- enum definitions;
- database constraints.

A relationship should not be inferred merely because it would be common in a typical e-commerce application.

## EXT-6 ERD Confirmation Record

EXT-6 reviewed Figure 6 of the approved manuscript against this context, the related Linear issues, and the existing Laravel authentication schema. Figure 6 is the authoritative logical ERD, but it does not specify all implementation details needed for migrations. In particular, it omits most string lengths, decimal precision, nullability, defaults, enum members, indexes, delete behavior, and lifecycle behavior.

The following decisions are **approved implementation decisions within the existing manuscript scope**. They do not amend the manuscript:

- use Laravel-compatible unsigned big integer primary and foreign keys rather than interpreting the ERD's generic `int` labels as a required physical storage size;
- retain Laravel authentication infrastructure fields and tables required by the installed authentication features, including `email_verified_at`, `remember_token`, conventional timestamps, password reset tokens, and sessions;
- add `forecasts.method` so persisted moving-average and least-squares linear-trend results can be distinguished;
- treat Sagay City as the sole operational branch for live sales and inventory, select it through application or configuration logic, and keep the `branches` schema aligned with the current manuscript ERD without adding `branches.is_primary`;
- add an `is_active` boolean field with a default value of `true` to categories, products, and chatbot knowledge; use neither a lifecycle status enum nor Laravel soft deletes for these records;
- use `DECIMAL(12,2)` for monetary values, matching unsigned integer types for quantities, and explicit foreign-key, uniqueness, and non-negative-value constraints where required by the approved relationships and business rules;
- use the enum values `customer` and `administrator` for user roles, `pending`, `processing`, `completed`, and `cancelled` for order status, `pending`, `verified`, and `rejected` for payment status, `moving_average` and `linear_trend` for forecast method, and `product`, `order`, `store`, and `faq` for chatbot category.
- expose **8:00 AM–6:00 PM** as the static customer-facing operating-hours value for all four branches through application configuration, without adding an `operating_hours` column to the manuscript ERD.
- seed only confirmed branch addresses and contact numbers into the matching manuscript ERD fields; keep unavailable values null and do not invent placeholders.
- keep branch email reference data in application configuration because the current manuscript ERD does not define a `branches.email` column.
- keep deactivated categories, products, and chatbot knowledge in the database for historical and administrative reference, allow administrators to reactivate them, and prohibit physical deletion when historically referenced;
- exclude inactive products from the customer catalog, cart eligibility, and recommendation results; exclude inactive categories and their products from customer browsing; and exclude inactive chatbot knowledge from chatbot retrieval;
- keep recommendation behavior rule-based using budget, intended use, preferred brand, category, product preferences or tags, and Sagay inventory; do not implement a `product_compatibilities` table, product-to-product compatibility relationship, or dedicated compatibility-checking/configurator feature.

The following E-Commerce decisions were explicitly deferred from EXT-6 to focused blocking issues:

- EXT-80 must confirm the exact payment methods Battlefront accepts for manual processing before EXT-32 implements checkout payment-method validation;
- EXT-81 must decide whether orders snapshot recipient name, contact number, fulfillment method, and delivery address before EXT-31 implements order persistence.

These decisions remain unresolved, but their explicit deferral and blocking relationships allow EXT-6 and the Core System schema-confirmation gate to close without allowing the affected E-Commerce work to guess requirements.

---

# 12. Approved Scope Boundaries

## Branch Operations

Outside scope:

- multi-branch inventory synchronization;
- branch-specific operational customization.

Sagay City remains the primary operational branch.

Escalante City, San Carlos City, and Guihulngan City are mainly customer-reference locations containing static branch/store information.

The customer-facing operating-hours value for each listed branch is **8:00 AM–6:00 PM** and remains reference information rather than branch-specific operational customization.

## Payments

The system supports:

- payment-method selection;
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

Forecasting uses Battlefront's historical sales and inventory records.

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

---

# 14. Expected Development Sequence

## 1. Core System

- Authentication
- Users
- Products
- Categories
- Inventory

## 2. E-Commerce Flow

- Cart
- Checkout
- Orders

## 3. Intelligent Modules

- Product Recommendation
- Predictive Analytics

## 4. Chatbot Integration

- Query categorization
- Database/context retrieval
- Gemini integration

## 5. Mobile API and Integration

- Laravel REST API contracts/endpoints
- Flutter/backend integration

## 6. System Integration and Testing

- Integration of major modules
- Automated and manual testing
- Web/mobile backend consistency

## 7. Deployment and Evaluation

- Sagay City deployment/testing
- System evaluation

This sequence represents the manuscript's general development progression and may be decomposed into smaller implementation issues.

Use this sequence to organize Linear projects, issues, dependencies, and sub-issues. Do not treat the full sequence as authorization to implement multiple modules at once.

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
- forecasting calculations;
- deterministic chatbot categorization;
- REST API behavior required by Flutter.

Postman is used for manual REST API endpoint testing where appropriate.

## Capstone Evaluation

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

## Approved Project Scope

Authority for determining what Battlefront is approved to contain:

1. Approved Chapters 1–3 manuscript
2. Adviser-approved amendments to that manuscript

An adviser-approved amendment supersedes the affected part of the earlier manuscript.

## Implementation Decisions

Within the approved project scope, use the following context hierarchy:

1. Approved scope and architecture
2. Explicit developer-approved implementation decisions
3. `CAPSTONE_CONTEXT.md`
4. Approved Linear issue and acceptance criteria
5. Existing project conventions

`CAPSTONE_CONTEXT.md` is an implementation reference, not authority to override the approved manuscript.

If an implementation requirement conflicts with the approved manuscript or an adviser-approved amendment, the conflict must be identified rather than silently resolved.
