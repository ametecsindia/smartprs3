# SmartPRS 3.0 — website pricing update (12-11-2026)

## Before (what the SmartPRS3 site showed)

- Cloud plans: Starter ₹1,000/mo (25 employees), Growth ₹2,500/mo (75), Professional ₹5,000/mo (150);
  extra employee ₹60 / ₹50 / ₹40 a month; extra company ₹1,000/mo; minimum 3 months advance,
  10% off for 6 months, 25% off for 12 months.
- On-premise: "perpetual licence" with Basic / Advance / Collection DNA chips, no price shown.
- Stats strip: "Cloud or L1/L2/L3 licence".
- FAQ page (/faqs): the same cloud rates, the advance discounts, ₹1,000 per extra company, and
  "coupons combine with the advance discount".

## After (owner's SmartPRS 3.0 prices, smartprs.com's format and logic — owner, 12-11-2026)

Prices live in `config/landing.json` → `pricing` (editable in Super Admin → Landing Page (CMS)); GST from
`config/gst.json`. Calculator: `public/js/pricing.js`; `node tests/pricing_calc_test.js` checks it against SmartPRS 2's
`BillingController::priceFor` + `SignupController::withSetup`, written out independently (254 checks).

| Cloud plan | Per month | Employees included | Extra employee |
|---|---:|---:|---:|
| Starter | ₹1,500 | 25 | ₹60/mo |
| Growth | ₹2,500 | 50 | ₹50/mo |
| Professional | ₹4,500 | 100 | ₹45/mo |
| Above 100 | Contact Sales | | |

Same logic as smartprs.com: extra company ₹1,000/mo; minimum 3 months advance (0%), 6 months 10% off, 12 months 25% off
(on the whole subscription incl. extras); setup & onboarding ₹2,500 + ₹100 per employee, not discounted; GST 18% on all.
On-Premises: licence ₹3,50,000 one time · installation ₹5,000 one time · AMC 20% = ₹70,000 a year. QR Attendance included in both.

Hand checks (GST 18%):
- Growth, 50 employees, quarterly: ₹2,500 × 3 = ₹7,500 + setup ₹7,500 = ₹15,000 + GST ₹2,700 = ₹17,700.
- Starter, 25 employees, annual: ₹1,500 × 12 = ₹18,000 − 25% = ₹13,500 + setup ₹5,000 = ₹18,500 + GST ₹3,330 = ₹21,830.
- Starter with 30 employees: 5 extra × ₹60 = ₹300/mo on top of ₹1,500.
- On-Premises, 50 employees: ₹3,50,000 + ₹5,000 + ₹7,500 = ₹3,62,500 + GST ₹65,250 = ₹4,27,750; AMC ₹70,000 + GST = ₹82,600 a year.

## For management to confirm

1. **Onboarding formula** — read as ₹2,500 **plus** ₹100 per employee. If it should be ₹100 per employee with a
   ₹2,500 minimum, set `pricing.onboarding.mode` to `max`.
2. **AMC first year** — the Licence Agreement (§4) says the first 12 months are included; the site says so and the
   calculator shows AMC "from year 2". Set `pricing.onprem.amc_first_year_included` to 0 if AMC is charged from year 1.
3. **Licensed capacity of the lifetime licence** — not stated; the site says it is confirmed in the quotation.
4. **Extra employees and "Above 100"** — the extra-employee rates are the brief's effective rates (₹60 / ₹50 / ₹45),
   shown as smartprs.com's "Extra employee" line. Because above 100 is Contact Sales, Professional's ₹45 never applies
   on the site; if Professional should take extras above 100 (as smartprs.com does), say so.
5. **Super Admin → Plans** (the billing records used for subscriptions and invoices) were not changed.
6. **App download link** — no public Play Store link yet (`app.play_url` is empty); the site says "ask us for access".
7. **Mobile app screenshots** — the site shows phone screens only from `public/images/app/` (dashboard, qr-scan,
   attendance, leave, payslips, self-service .jpg). None are there yet, so it shows the feature list instead.
