# Local PSGC delivery locations

Battlefront bundles nationwide geographic reference data in `resources/data/psgc-nationwide-2026-03-31.v2.json` and 18 regional `psgc-barangays-2026-03-31.v2-*.json` partitions. The registry contains **18 regions, 82 provinces and 1,642 cities/municipalities**; the partitions contain **42,010 barangays**. `PsgcDeliveryLocations` validates addresses locally; `DeliveryRules` alone determines delivery coverage, fees, shipping profiles, payment eligibility and estimates. Nationwide address selection does not enable nationwide automatic checkout.

The original `psgc-delivery-2026-03-31.v1.json` remains a bounded compatibility payload for existing consumers and conservative legacy address matching. Geography requires no tables or runtime network access. The shipping-review/quote-metadata migration is required for the enhanced product and order persistence.

## Source and version

- Publisher: Philippine Statistics Authority (PSA), [Philippine Standard Geographic Code](https://psa.gov.ph/classification/psgc/).
- Data release: **Q1 2026, as of 31 March 2026**. Nationwide local schema version: **2**; retained compatibility schema: **1**.
- Prepared on **13 October 2026** from the [PSA-derived psgc-rb data](https://github.com/alkevintan/psgc-rb/tree/279ffe1918c2fd28206c2153f6c5c2d8280fb9f3/data).
- Pinned revision: `279ffe1918c2fd28206c2153f6c5c2d8280fb9f3`.
- Source file Git blob hashes: regions `4b7efc1bc611f7a365027fb2b359c2a4eb36d03e`; provinces `41452fd6dff02c71a2f840ad78bd9944c2bd372f`; cities/municipalities `250aba0b930310650a0816fffd31a103ffb2bef8`; barangays `49f9fa82420187596178ee958855ec7fd4cf4710`.
- The official Q2 2026 workbook was located, but its direct download returned a Cloudflare 403 in the preparation environment. This bundle deliberately identifies the obtainable Q1 release; it is not labeled as the latest release.

Official string codes and names are retained nationwide. The following **12 configured delivery routes** and their **300 barangays** also remain available in the compatibility payload. Destination aliases remain Battlefront's exact configured strings; for example, `E.B. Magalona` maps to official `Enrique B. Magalona`.

| Destination key | Full locality code | Barangays |
| --- | --- | --- |
| Sagay City | 1804523000 | 25 |
| Escalante City | 1804509000 | 21 |
| Cadiz City | 1804504000 | 22 |
| Toboso | 1804529000 | 9 |
| Manapla | 1804518000 | 12 |
| Calatrava | 1804505000 | 40 |
| Victorias City | 1804531000 | 26 |
| E.B. Magalona | 1804508000 | 23 |
| San Carlos City | 1804524000 | 18 |
| Silay City | 1804526000 | 16 |
| Talisay City | 1804528000 | 27 |
| Bacolod City | 1830200000 | 61 |

### Normalization and administrative identity

The mirror's `city_municipality_code` truncates barangay parent codes to six digits, which groups different localities together; its `province_code` is also truncated. Neither field was imported. Membership was rebuilt using the first **seven** digits of the full 10-digit locality/barangay codes and confirmed against [PSA locality listings](https://psa.gov.ph/classification/psgc/citimuni/1804500000). The service checks these relationships and unique string codes when loading JSON.

Bacolod is a [highly urbanized city](https://psa.gov.ph/classification/psgc/hucs), code `1830200000`, in Negros Island Region. It has no administrative province parent in the normalized data. The other supported cities/municipalities have Negros Occidental, code `1804500000`, as their province. Fixed delivery coverage still includes Bacolod.

The mirror includes 14 Manila districts among its locality rows. These are retained as district metadata under the actual City of Manila (`1380600000`), with 897 barangays, rather than presented as separate cities. NCR has 17 city/municipality records and no province selector requirement. HUCs, Isabela City and the BARMM Special Geographic Area localities retain null administrative province parents; a null province does not by itself mean HUC. Negros Island Region (`1800000000`) contains Negros Occidental, Negros Oriental and Siquijor; Bacolod remains independent of an administrative province.

Barangays are sorted naturally by official name, then code. Population, income and coordinates are omitted. Codes, region/province/city membership, attribution and release metadata are retained. No runtime external PSGC API is used.

## Checkout and compatibility

Inertia checkout and customer profile settings use cascading Region → Province/Administrative Area (when applicable) → City/Municipality → Barangay → Street/House/Landmark fields. Independent localities appear in a no-province administrative area option. Changing a parent clears descendants; stale asynchronous responses cannot restore old choices. Failed lookups expose retry guidance.

Existing checkout preview `deliveryLocations`/`data.delivery_locations` and prefill fields retain their bounded v1 contracts. Updated clients lazily request authenticated local `GET /delivery-locations` (web) or `GET /api/v1/delivery-locations` (bearer customer). Send `level=regions`, `provinces&parent_code=<region>`, `cities&parent_code=<province>` or `<region>` for independent localities, `city&parent_code=<city>` for hydration, and `barangays&parent_code=<city>`. Responses are `{data:{version,locations}}`. Codes are strings; unknown or invalid parents return 422. The complete dataset is never sent to checkout.

New structured submissions include `delivery_region_code`, nullable `delivery_province_code`, `delivery_city_code`, `delivery_barangay_code` and `delivery_address_details`. The city code can be used as `delivery_destination`, or that field can be omitted and resolved from the city code. Parent-child membership and any supplied destination alias are checked server-side. Profile fields use the `default_delivery_` prefix. Valid unpriced nationwide addresses may be saved as customer defaults.

Structured delivery sends the existing canonical `delivery_destination`, a `delivery_barangay_code` string, and `delivery_address_details`. Laravel verifies coverage, barangay membership, and nonblank details, then composes the order's existing `delivery_address` snapshot. The assembled string must fit 255 Unicode characters; it is never truncated. Supplied assembled addresses are ignored in structured mode.

Examples:

- Sagay: `12 Mabini Street, Poblacion I, Sagay City, Negros Occidental`.
- Bacolod: `12 Lizares Street, Barangay 13, Bacolod City`.

Web delivery uses nationwide structured fields. Existing mobile clients may continue sending an original configured destination alias and `delivery_address` when structured fields are absent, or the original v1 barangay/details contract. Sending any nationwide parent field or a code destination opts into complete nationwide hierarchy validation. Pickup requires no address and prohibits nonempty delivery fields.

Customer Profile Settings uses the same shared address fields as checkout. A nullable `users.default_delivery_address_components` JSON column saves the canonical destination, server-resolved full city code, barangay code, and detailed street text. The existing `default_delivery_address` string remains the readable compatibility representation. Profile saves require all three valid fields, and explicit clearing clears both representations. Checkout reads valid saved components directly and never updates either profile field.

Existing free-text addresses remain untouched by the additive migration. Conservative prefill recognizes exact comma-delimited canonical/official city suffixes and known barangay names, accepting case differences and explicit `Brgy.`/`Barangay` prefixes. It removes a trailing `Negros Occidental` label when parsing older addresses, including Bacolod. It never guesses a barangay. A confidently matched city may prefill independently; barangay/details prefill only when both the barangay and remaining detail text are unambiguous. Uncertain saved text remains visible as an escaped reference. Unrelated profile saves use `default_delivery_address_action=keep`, retaining even partially recognizable legacy defaults. Customer edits use `save`; explicit removal uses `clear`. Legacy mobile free-text updates remain supported and refresh or clear stale components using the same conservative matcher.

Orders retain their purchase-time human-readable address and all previous commercial/Shipment snapshots. Nullable `orders.delivery_quote_metadata` adds accepted codes, dataset/rule versions, payment eligibility/assumption and structured address components for new quotes; historical rows remain null and are never repriced. Apply the profile-components and shipping-review/quote-metadata migrations before deployment; rollback refuses to discard populated metadata.

## Configured demonstration coverage and product handling

Sagay City is the single origin. `battlefront.delivery.zones` resolves city rules before province rules before region rules. Specific disabled or incomplete rules stop resolution and reject delivery; they do not fall through to broader coverage. Each enabled rule requires a decimal-string `base_fee`, nonnegative integer `transit_min_days`/`transit_max_days`, and explicitly allowed `payment_methods` from `gcash`, `maya`, `cod`. Legacy city rules reference the existing `destinations` table. Province/region configuration is currently empty. Do not add nationwide prices without approved values.

All amounts and day ranges below are **Battlefront-configured demonstration estimates, not official LBC quotations or transit guarantees**:

| Routes | Base fee (PHP) | Transit days |
| --- | --- | --- |
| Sagay City | 80.00 | 0–1 |
| Escalante City | 100.00 | 1 |
| Cadiz City | 120.00 | 1 |
| Toboso | 140.00 | 1 |
| Manapla | 160.00 | 1 |
| Calatrava, Victorias City | 180.00 | 1 |
| E.B. Magalona | 200.00 | 1–2 |
| San Carlos City, Silay City | 220.00 | 1–2 |
| Talisay City | 240.00 | 1–2 |
| Bacolod City | 250.00 | 1–2 |

All 12 explicitly support GCash, Maya and COD demonstrations. Every other valid nationwide destination may submit prepaid GCash/Maya checkout with a server-authoritative `0.00` delivery fee. These orders include the product subtotal only, expose no transit/calendar estimate, and create no LBC shipment because third-party logistics are not configured. Their immutable metadata records `pricing_status=not_configured`, the accepted geography, eligible methods, and the no-fee/logistics assumption. COD remains limited to configured routes because it depends on a courier collection arrangement.

The quote endpoint is `GET /checkout/delivery-quote` (web) or `GET /api/v1/checkout/delivery-quote` (mobile), with owned selected `cart_item_ids[]` and `delivery_city_code`. A configured route returns `{data:{available:true,quote:{pricing_configured:true,...}}}`. An unconfigured valid route returns `{data:{available:true,quote:{pricing_configured:false,delivery_fee:"0.00",total:<product subtotal>,carrier:null,estimated_delivery_start:null,estimated_delivery_end:null,...}}}`. Explicitly disabled or malformed configured rules return `{data:{available:false,quote:null,notice}}`. Placement independently rechecks current configuration and payment eligibility under its existing transaction; preview results never authorize a client fee.

| Profile | Once-per-order surcharge (PHP) | Preparation days |
| --- | --- | --- |
| Standard | 0.00 | 1 |
| Fragile | 50.00 | 2 |
| Bulky | 100.00 | 3 |

The highest profile in the selected cart applies once. Delivery fee = configured destination base fee + that surcharge; final total = product subtotal + delivery fee. No valuation, insurance, weight/dimension or live carrier pricing is implemented.

`catalog-shipping-profiles.v1.json` reviews all 654 imported identities: 93 clear Standard accessories, 93 Fragile items (including actual displays, motherboards, graphics cards and explicit laptops), 46 Bulky items (including cases, clear desktop systems, printers and chairs), and 422 uncertain identities requiring administrator review. Uncertain items retain their existing profile rather than receiving an invented assignment. Accessory brackets are not classified as fragile displays. Mapping application requires an exact code/name/category match, an imported product, current Standard and no review source. Existing non-Standard/manual/source-tagged assignments are preserved.

`php artisan catalog:classify-shipping --no-interaction` previews the mapping; `--apply` applies eligible reviewed rows in locked transactions. New catalog inserts receive eligible initial classifications; reimports preserve existing profiles. Administrators can change or explicitly confirm each profile in the existing product form; `shipping_profile_source=manual` protects that choice, including a confirmed Standard assignment. Apply the migration before running the command.

For configured routes, GCash/Maya pay the full total to Battlefront's configured wallet with existing manual proof verification; Battlefront is assumed to arrange shipment and pay the carrier at dispatch. For unconfigured routes, GCash/Maya pay the product subtotal only and the order makes no carrier booking or delivery-price promise. COD assumes collect-both product payment and delivery fee only on configured routes, pending branch confirmation. Administrator confirmation of successful collection remains required before payment verification/completion. Pickup cash/card workflows have zero delivery fees. Sales revenue remains product subtotal only. No carrier settlement or reconciliation module is introduced. The full operational confirmation notice appears only in Sagay branch information; the standard estimated-rate disclaimer accompanies configured shipping quotes.

## Updating the bundle

1. Find a verified PSA quarterly publication or a properly licensed mirror that explicitly identifies its PSA release. Retrieve the source during development/setup, never during checkout.
2. Pin the mirror revision or official publication URL and retain source hashes. Download `regions.json`, `provinces.json`, `cities_municipalities.json` and `barangays.json` into a temporary working directory only. For this release, use the raw URLs under the pinned revision above. GitHub's blob endpoint can retrieve large files.
3. Reproduce the current bundle with `node database/PrepareNationwidePsgc.mjs <source-directory>`. The transformer checks all four pinned Git blob hashes and expected counts before writing registry/partitions. Different source bytes require a reviewed source/version update; never bypass hash verification.
4. Review full-code parents, NCR/HUC/Manila/SGA/NIR exceptions and all 12 aliases. Never import truncated mirror parent fields or guess replacement identities. Validate unique string codes and every barangay parent.
5. For a new release, update source/license metadata and versioned filenames/reader references together. Review geographic changes separately from approved delivery configuration; a data refresh cannot silently enable routes or rewrite historical addresses.
6. Run the focused PSGC service, checkout, mobile compatibility, and frontend address tests. Existing orders must remain readable without looking up current geography.
7. Retain applicable notices and attribution below. Deploy validated JSON with application code; geographic refreshes do not require tables or runtime downloads.

Do not update this bundle automatically on a schedule. Geographic updates are reviewed source changes and cannot expand delivery eligibility.

## Attribution and licenses

PSA data is available under [CC BY 4.0 / PSA Terms of Use](https://psa.gov.ph/terms-of-use). Credit PSA, link to the terms, retain relevant technical explanations, indicate modifications, and do not imply PSA endorsement. This document and the JSON source metadata identify our filtering, parent normalization, classification metadata, and sorting.

The mirror uses MIT. Retain its notice when distributing this derived dataset:

> MIT License
>
> Copyright (c) 2026 Al Kevin Tan
>
> Permission is hereby granted, free of charge, to any person obtaining a copy
> of this software and associated documentation files (the "Software"), to deal
> in the Software without restriction, including without limitation the rights
> to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
> copies of the Software, and to permit persons to whom the Software is
> furnished to do so, subject to the following conditions:
>
> The above copyright notice and this permission notice shall be included in all
> copies or substantial portions of the Software.
>
> THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
> IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
> FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
> AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
> LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
> OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
> SOFTWARE.
