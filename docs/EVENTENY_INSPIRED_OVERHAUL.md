# Eventeny-inspired Delta H overhaul

## Goal

Turn Delta H from a volunteer-only operations portal into a self-hosted anime-convention platform with two connected surfaces:

1. a public, mobile-first anime convention website for attendees; and
2. the existing authenticated operations portal for volunteers and staff.

The first production-complete vertical slice is public event discovery plus server-owned ticket pricing, Stripe-hosted Checkout, verified payment webhooks, and ticket issuance. Stripe starts in test mode. No card data touches Delta H.

## Source reconnaissance (2026-07-24)

Official Eventeny product and help pages reviewed:

- <https://www.eventeny.com/why-eventeny>
- <https://www.eventeny.com/>
- <https://help.eventeny.com/en/articles/14743984-eventeny-index>
- <https://help.eventeny.com/en/articles/14743974-payment-processing>
- <https://www.eventeny.com/product/pricing>

Official Stripe implementation guidance reviewed:

- <https://docs.stripe.com/payments/checkout/how-checkout-works>
- <https://docs.stripe.com/checkout/quickstart?lang=php>
- <https://docs.stripe.com/webhooks/signature>

### Eventeny capability map

| Capability family | Eventeny offering | Delta H position before this overhaul | Delta H direction |
|---|---|---|---|
| Public event site | Event pages, schedules, maps, attendee discovery | No public event website | Ship an anime-styled public site with event, schedule, guests, vendors, map, sponsors and FAQ sections |
| Ticketing | Ticket types, sales, attendee management, check-in | No ticket sales | Ship server-owned ticket catalog, Stripe Checkout, order state and issued ticket codes |
| Applications | Artists, vendors, sponsors and other applicants | Volunteer application only | Reuse the application workflow pattern; add typed applicant pipelines in a later vertical slice |
| Vendor management | Applications, invoices, booth placement and communication | Staff-only vendor-hall assignment map | Expose a public vendor directory now; later connect applicants, invoices and booth placement |
| Scheduling | Program schedules and operational scheduling | Volunteer shift scheduler | Keep staff shifts separate; add public programming data and filters |
| Maps | Interactive event maps and booth discovery | Staff-only vendor-hall map | Publish a read-only attendee map; later add searchable booth/program overlays |
| Volunteers | Volunteer applications, assignments and communication | Strong existing volunteer operations | Preserve and integrate with public calls-to-action |
| Sponsors | Sponsor applications and visibility | None | Public sponsor strip now; application pipeline later |
| Communications | Email/SMS/in-app updates | Discord operational integration | Preserve Discord for staff; add transactional ticket email in a later slice |
| Analytics | Sales and operational reporting | Operations dashboards only | Add order/ticket reports after checkout data is proven |

## Reuse/adoption decision

### Evaluated substrates

| Candidate | What it would provide | License / integration cost | Decision |
|---|---|---|---|
| Hi.Events | Broad ticketing and event management | AGPL-family codebase, React/Laravel stack, substantial replacement cost | Do not fork into Delta H; use only as product-reference research |
| pretix | Mature ticketing and Stripe support | AGPLv3 with additional terms; separate Python/Django system | Do not embed or copy; potentially support as a future external ticketing adapter |
| Attendize | Laravel ticketing baseline | Attribution Assurance License requires prominent attribution; aging full-stack replacement | Do not adopt |
| Stripe Checkout | Hosted PCI-scoped payment page, test mode, webhooks | Official HTTP API; no frontend card handling | Adopt as the payment substrate |

Delta H remains a lightweight PHP 8.2 + MariaDB application. Replacing it with a large event platform would discard the working volunteer, Discord, hotel, incident and vendor-hall operations already present. The overhaul therefore adopts Stripe Checkout and builds focused event modules behind Delta H's existing deployment model.

## Architecture

### Public surface

The logged-out root page becomes a real convention website, not a login wall. It contains:

- sticky public navigation;
- manga/anime-inspired hero and event facts;
- program schedule with day/category filters;
- featured guests;
- vendor/artist directory;
- venue and hall map;
- ticket cards and quantity controls;
- volunteer and vendor calls-to-action;
- sponsor strip and FAQ;
- a clearly separated staff/volunteer login drawer.

All text and product data come from `public_event` API data so deployment-specific facts are configured server-side rather than baked into browser code.

### Payments

1. Browser requests `public_event` and renders server-owned ticket SKUs and prices.
2. Browser posts only `sku`, `quantity`, purchaser email, and an idempotency token to `create_checkout`.
3. Server validates SKU and quantity against its own catalog, writes a pending order, and creates a Stripe Checkout Session.
4. Browser redirects to the Stripe-hosted URL.
5. Stripe sends `checkout.session.completed` to `stripe_webhook`.
6. Server verifies `Stripe-Signature` against the raw request body and configured webhook secret.
7. The webhook idempotently marks the order paid and issues one random ticket code per purchased admission.
8. The success page polls `checkout_status` using the Stripe session id and displays fulfillment status and ticket codes only after payment is verified.

Security invariants:

- no Stripe secret or webhook secret is sent to the browser;
- no client-supplied amount, currency, product name, success URL or cancel URL is trusted;
- Stripe webhooks are signature verified with a bounded timestamp tolerance;
- checkout creation is idempotent;
- webhook delivery is idempotent;
- checkout is test-mode by default;
- Delta H never receives or stores card data.

## Delivery phases

### P0 — complete vertical slice in this branch

- public anime convention website shell and responsive design;
- configurable event content and ticket catalog;
- Stripe test-mode Checkout Session creation;
- pending orders, webhook verification, paid-state transition and ticket issuance;
- checkout success/cancel experience;
- PHP unit tests for signature verification, catalog validation and fulfillment idempotency;
- existing security/schema/release tests kept green;
- allowlisted release includes the payment module.

### P1 — applicant marketplace

- typed vendor, artist, guest and sponsor applications;
- review queues, application fees and invoices;
- acceptance/decline messaging;
- accepted applicant → public directory and booth assignment.

### P2 — attendee operations

- QR rendering and scanner/check-in mode;
- transfer/refund policy workflow;
- attendee email receipts and tickets;
- public program schedule editor and favorites;
- attendee-facing interactive map.

### P3 — organizer analytics and communications

- revenue, sales, attendance and conversion dashboards;
- announcements and segmented email/SMS adapters;
- reconciliation/export tools;
- audit-grade payment and webhook delivery views.

## Configuration required for live use

The repository ships placeholders only. Plesk deployment must set:

- `stripe_secret_key` — use `sk_test_...` first;
- `stripe_webhook_secret` — use `whsec_...` from the deployed webhook endpoint;
- public event name, date, venue and canonical URL;
- final ticket catalog and prices.

No production charge path is enabled or tested with real customer funds in this branch.
