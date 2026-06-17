# Commercial Readiness Audit — e-BAL

**Date:** 2026-06-18
**Scope:** Full-stack audit of pricing, billing, licensing, and branding
**Methodology:** Codebase scan + file review (no code modifications)
**Overall Score:** 6.5/10 — **CONDITIONAL GO** (8 gaps to close before public launch)

---

## Phase 1: Keyword Scan

| Element | Status | Files |
|---------|--------|-------|
| Plan/pricing model | ✅ Present | `plan_helper.php` — 3 tiers (Starter/Professional/Pro Plus) |
| Payment gateway | ✅ Present | `razorpay_helper.php` — Razorpay payment links |
| License enforcement | ✅ Present | `license_check.php` — middleware, redirects to `upgrade.php` |
| Billing audit trail | ✅ Present | `license_transactions` table — 5 transaction types, 4 payment statuses |
| Revenue reporting | ✅ Present | `getRevenueSummary()`, `getRevenueByMonth()`, `getRecentLicenseActivity()` |
| Usage limits | ✅ Present | `canAddCompany()`, `canAddUser()`, `hasFeature()` |
| Admin management UI | ✅ Present | `workspace_admin.php` — create client admins, assign plans |
| Self-service upgrade | ✅ Present | `upgrade.php` + `create_payment_link.php` + `payment_result.php` |

---

## Phase 2: Pricing Model

| Plan | Price (INR/yr) | Companies | Users | AI | Notes |
|------|---------------|-----------|-------|----|-------|
| Starter | 2,999 | 5 | 1 | ❌ | |
| Professional | 4,999 | 10 | 3 | ✅ | |
| Pro Plus | 9,999 | Unlimited | 5 | ✅ | |

**Gaps:**
- No free trial / freemium tier
- No monthly billing option (annual only)
- No per-company or per-user add-on pricing (hard jumps between tiers)
- Prices hardcoded in seed data — no admin UI to modify
- No GST/tax line item in plans or transactions

---

## Phase 3: Payment/Billing Flow

```
upgrade.php → create_payment_link.php → Razorpay Checkout → payment_result.php → license activated
                                                                      ↕ (webhook fallback)
```

**Gaps:**
- No trial/free plan to let prospects evaluate before purchase
- No proration logic for mid-cycle upgrades/downgrades
- No invoice/receipt generation
- No dunning emails (expiring/expired license reminders)
- `payment_result.php` reads `razorpay_payment_link_reference_id` from GET params — susceptible to manipulation (mitigated by webhook fallback, but still)
- No payment retry/retry URL for expired links
- No coupon/discount/promo code system

---

## Phase 4: Billing Flow Trace (End-to-End)

| Step | File | What Happens | Issues |
|------|------|-------------|--------|
| 1 | `upgrade.php` | Shows current plan, usage, catalog, recent payments | No CSRF on `<form>` (csrfInput() is called but render depends on helper) |
| 2 | `create_payment_link.php` | Creates Razorpay payment link, redirects user to Razorpay | No idempotency key — duplicate submissions could create multiple links |
| 3 | Razorpay | User enters card/UPI details on Razorpay hosted page | Relies entirely on Razorpay's UI (no white-label option) |
| 4 | `payment_result.php` | Callback handler — syncs payment, activates license | GET params could be tampered; relies on webhook as backup |
| 5 | Webhook | Razorpay pings server → `syncRazorpayPaymentLinkEntity()` | Webhook handler file location unclear — may not exist yet |
| 6 | `workspace_admin.php` | Superadmin can manually assign plans (offline payment) | No audit trail linking manual assignments to actual money received |

---

## Phase 5: License Architecture

### Schema (5 tables + 1 column)

```
users ──→ licenses ──→ license_transactions
  │                     ↑
  └── plans (reference) │
                        └── razorpay_payment_links
```

### Enforcement Chain

```
session_bootstrap.php → header.php → license_check.php
                                         │
                                    ├─ superadmin? → skip
                                    ├─ upgrade.php/login/logout? → skip
                                    └─ else → getActiveLicense() → expired? redirect to upgrade.php
```

### Gaps
- `company_limit >= 999` is a magic number for "unlimited" — fragile if limits ever increase
- `canAddCompany()` / `canAddUser()` enforcement is only in `company_create.php` — other create paths may bypass
- No soft-limit warning before hard block
- No license history viewer (who changed what, when)
- No offline-license fallback (all checks require DB — no cached/token-based validation)
- No seat-based licensing (users are counted but not validated per-seat at login)
- Plans are missing: `trial_days`, `is_public`, `billing_cycle`, `sort_order` columns

---

## Phase 6: Branding Inventory

### User-Facing Branding

| Location | Current Text | Status |
|----------|-------------|--------|
| `header.php` logo | `e-BAL` | ✅ Product name |
| `header.php` <title> | `[page] | e-BAL` | ✅ |
| `header.php` sidebar | `e-BAL` / `eB` | ✅ |
| `header.php` tagline | `Structured balance sheet workflow...` | ✅ Professional |
| `login.php` <title> | `Login | e-BAL` | ✅ |
| `login.php` heading | `e-BAL Login` | ✅ |
| `footer.php` | `© [year] e-BAL | Structured Balance Sheet Tool by E Tax Advisors Private Limited` | ⚠️ Company name embedded |
| `connector.php` | `e-BAL Smart Bridge` | ✅ |
| `XLSX metadata` | `e-BAL` / `e-BAL` | ⚠️ White-label concern |
| `razorpay_helper.php` | `e-BAL [plan] annual subscription` | ⚠️ Visible on Razorpay checkout |
| Log prefix (`runtime_helper.php`) | `[eBAL]` | ✅ Internal only |

### Environment

| Item | Value | Status |
|------|-------|--------|
| Production URL | `https://ebal.etaxadv.com/` | ⚠️ Subdomain of parent company |
| DB name | `etaxadv_ebal` | ⚠️ Parent company in name |
| DB user | `etaxadv_ebaluser` | ⚠️ Parent company in name |
| Bridge API client ID | `EBAL001` | ✅ Acceptable for internal |
| Cpanel deploy path | `/home5/etaxadv/public_html/ebal` | ⚠️ Parent company visible in infra |

### Gap Summary
- **No "beta" / "trial" / "demo" labels** in code → actually good for commercial perception
- **No "powered by" watermark** → good, but means no white-label controls either
- **Parent company name** (`E Tax Advisors`) appears in footer, DB names, deploy paths, and Razorpay checkout description
- **XLSX exports** hardcode `e-BAL` as creator — cannot be rebranded per-client

---

## Phase 7: Gap Analysis

### Critical (Blocking for public launch)

| # | Gap | Severity | Description |
|---|-----|----------|-------------|
| G1 | No trial/free plan | HIGH | Prospects cannot evaluate before purchasing. Zero conversion path. |
| G2 | No email notifications | HIGH | No dunning (expiry reminders), no invoice delivery, no payment confirmation emails |
| G3 | No invoice/receipt | HIGH | Indian businesses require GST invoices for compliance. `license_transactions` stores amount but no tax fields. |
| G4 | Pricing not configurable | HIGH | Prices hardcoded in seed data. No admin UI for promos, discounts, or price changes. Requires DB edit. |

### Important (Conditional for commercial launch)

| # | Gap | Severity | Description |
|---|-----|----------|-------------|
| G5 | No usage enforcement at critical paths | MEDIUM | `canAddCompany()` checked only in `company_create.php`; API import paths may bypass |
| G6 | No proration | MEDIUM | Upgrading mid-cycle loses remaining value on current plan |
| G7 | Parent company branding visible | MEDIUM | `E Tax Advisors` in footer, DB, deploy paths — may confuse customers |
| G8 | No seat-level license validation | LOW | Users are counted but not individually authorized at login |

### Cosmetic (Non-blocking)

| # | Gap | Severity | Description |
|---|-----|----------|-------------|
| G9 | Magic number 999 for "unlimited" | LOW | `company_limit >= 999` should use a dedicated flag column |
| G10 | No webhook handler file discovered | LOW | The webhook endpoint (`/api/razorpay_webhook.php`?) needs to exist for async payment confirmation |
| G11 | No cache layer for license checks | LOW | Every page load queries DB for license status |
| G12 | XLSX creator hardcoded | LOW | Cannot rebrand exports per-client |

---

## Phase 8: Recommendations

### Pre-Launch (Must Fix)

1. **Add a trial plan** — 14-day free trial with Starter limits. Auto-convert to paid or expire. Simple: add `trial_days` column to `plans`, create a `TRIAL` code with 0 INR, and auto-assign on registration.
2. **Add invoice/receipt generation** — Extend `payment_result.php` to generate a simple PDF invoice (or email receipt HTML). Add `gst_percent`, `gst_amount`, `invoice_number` columns to `license_transactions`.
3. **Implement email notifications** — Add a `mail_helper.php` with SMTP config. Key triggers: payment received, license expiring (7/3/1 day before), license expired, payment failed.
4. **Make pricing configurable via admin UI** — Add a "Plan Settings" section to `workspace_admin.php` or a new `plan_settings.php` for editing plan prices, limits, and feature flags.

### Pre-Public Launch (Should Fix)

5. **Audit all company creation paths** — Ensure `canAddCompany()` check is enforced at every point where a company can be created (API imports, bridge sync, etc.).
6. **Add proration calculator** — When upgrading mid-cycle, credit remaining days and charge prorated difference. Implement in `create_payment_link.php`.
7. **Rebrand parent company references** — Consider whether `E Tax Advisors` should appear in customer-facing UI. If product is standalone: remove from footer, use generic DB names, and use a branded Razorpay description.
8. **Add seat-level enforcement** — In `license_check.php` or at login, verify that the user count does not exceed `user_limit` for the workspace owner's license.

### Post-Launch (Nice to Have)

9. Replace magic number `999` with a dedicated `is_unlimited` boolean column in `plans`.
10. Verify and document Razorpay webhook endpoint.
11. Add Redis/file-cache layer for `getActiveLicense()` to reduce DB queries.
12. Add configurable XLSX creator/branding in `financial_statement_xlsx.php`.

### Timeline Suggestion

```
Week 1-2: G1 (trial plan), G3 (invoices)
Week 3-4: G2 (email notifications), G4 (configurable pricing)
Week 5-6: G5 (usage enforcement), G6 (proration), G7 (branding), G8 (seat enforcement)
          → PUBLIC LAUNCH ←
Post-launch: G9, G10, G11, G12
```

---

## Final Verdict

```
Score:   6.5/10
Status:  CONDITIONAL GO
Blockers: 4 critical (G1-G4), 4 important (G5-G8)
Estimate: 6 weeks of development to close all critical+important gaps
```

The billing/licensing foundation is **structurally sound** and was clearly designed with commercial intent. The 5-table schema, Razorpay integration, and middleware enforcement are production-quality. What's missing are the **customer-facing layers** (trial, invoices, emails) and **operational tooling** (configurable pricing, usage enforcement) that turn a functional billing system into a sellable product.

--- END OF AUDIT ---
