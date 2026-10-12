# Local PSGC delivery locations

Battlefront bundles geographic reference data in `resources/data/psgc-delivery-2026-03-31.v1.json`. Setup needs no installation, migration, geographic tables, or network access. `PsgcDeliveryLocations` reads this file; `DeliveryRules` alone determines delivery coverage, fees, shipping profiles, and estimates.

## Source and version

- Publisher: Philippine Statistics Authority (PSA), [Philippine Standard Geographic Code](https://psa.gov.ph/classification/psgc/).
- Data release: **Q1 2026, as of 31 March 2026**. Local schema version: **1**.
- Prepared on **12 October 2026** from the [PSA-derived psgc-rb data](https://github.com/alkevintan/psgc-rb/tree/279ffe1918c2fd28206c2153f6c5c2d8280fb9f3/data).
- Pinned revision: `279ffe1918c2fd28206c2153f6c5c2d8280fb9f3`.
- Source file Git blob hashes: cities/municipalities `250aba0b930310650a0816fffd31a103ffb2bef8`; barangays `49f9fa82420187596178ee958855ec7fd4cf4710`.
- The official Q2 2026 workbook was located, but its direct download returned a Cloudflare 403 in the preparation environment. This bundle deliberately identifies the obtainable Q1 release; it is not labeled as the latest release.

Only 12 destination records and their **300 barangays** are retained. Original official codes and names are preserved. Destination keys remain Battlefront's exact configured strings; for example, `E.B. Magalona` maps to official `Enrique B. Magalona`.

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

Barangays are sorted naturally by official name, then code. Codes remain strings; source population, income, coordinates, and nationwide records are omitted. The JSON contains source metadata, a schema version, and explicit parent membership through each destination's barangay list. No runtime external PSGC API is used.

## Checkout and compatibility

Inertia checkout receives `deliveryLocations` and `deliveryAddressPrefill`. Mobile receives the same data as additive `data.delivery_locations` and `data.delivery_address_prefill` through its existing checkout preview. No additional lookup request is required.

Structured delivery sends the existing canonical `delivery_destination`, a `delivery_barangay_code` string, and `delivery_address_details`. Laravel verifies coverage, barangay membership, and nonblank details, then composes the order's existing `delivery_address` snapshot. The assembled string must fit 255 Unicode characters; it is never truncated. Supplied assembled addresses are ignored in structured mode.

Examples:

- Sagay: `12 Mabini Street, Poblacion I, Sagay City, Negros Occidental`.
- Bacolod: `12 Lizares Street, Barangay 13, Bacolod City`.

Web delivery requires structured fields. Existing mobile clients may continue sending `delivery_destination` and their original `delivery_address` when both new fields are absent. Sending either new field opts into complete structured validation, even if that field is null or empty. Pickup requires no address and prohibits nonempty delivery fields.

Customer Profile Settings uses the same shared address fields as checkout. A nullable `users.default_delivery_address_components` JSON column saves the canonical destination, server-resolved full city code, barangay code, and detailed street text. The existing `default_delivery_address` string remains the readable compatibility representation. Profile saves require all three valid fields, and explicit clearing clears both representations. Checkout reads valid saved components directly and never updates either profile field.

Existing free-text addresses remain untouched by the additive migration. Conservative prefill recognizes exact comma-delimited canonical/official city suffixes and known barangay names, accepting case differences and explicit `Brgy.`/`Barangay` prefixes. It removes a trailing `Negros Occidental` label when parsing older addresses, including Bacolod. It never guesses a barangay. A confidently matched city may prefill independently; barangay/details prefill only when both the barangay and remaining detail text are unambiguous. Uncertain saved text remains visible as an escaped reference. Unrelated profile saves use `default_delivery_address_action=keep`, retaining even partially recognizable legacy defaults. Customer edits use `save`; explicit removal uses `clear`. Legacy mobile free-text updates remain supported and refresh or clear stale components using the same conservative matcher.

Orders retain their purchase-time human-readable snapshot without new order columns. The profile JSON column is customer metadata, not a geographic table. Existing shipment snapshots, order history, and fee calculations are unchanged. Apply the profile-components migration before deploying profile saving; rollback refuses to drop populated component data.

## Updating the bundle

1. Find a verified PSA quarterly publication or a properly licensed mirror that explicitly identifies its PSA release. Retrieve the source during development/setup, never during checkout.
2. Pin the mirror revision or official publication URL and retain source hashes. Download nationwide source files into a temporary working directory only. For this source, use the raw URLs under the pinned revision above for `data/cities_municipalities.json` and `data/barangays.json`. GitHub's blob endpoint can also retrieve the barangay file by its recorded hash when the contents endpoint omits files over 1 MB.
3. Resolve exactly the 12 configured destination keys to verified full locality codes. Start with the mappings above, review official renamings/reclassifications, and stop if any mapping cannot be verified. Never guess a replacement code or name.
4. For each locality, select barangays using full codes with matching seven-digit locality prefixes. Preserve official names and full string codes. Do not copy the mirror's truncated parent metadata. Sort names with JavaScript `localeCompare(otherName, 'en', { numeric: true })`, breaking ties by code.
5. Preserve the JSON shape, update release/date/source/license metadata, and write a new versioned filename. Update the reader's file reference and the frontend test's data reference. Review geographic changes and all 12 expected barangay counts; coverage/pricing keys and values must remain unchanged.
6. Run the focused PSGC service, checkout, mobile compatibility, and frontend address tests. Existing orders must remain readable without looking up current geography.
7. Retain applicable notices and attribution below. Deploy the validated JSON with application code. No installation or database change is required.

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
