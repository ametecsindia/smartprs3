// SmartPRS 3.0 pricing — same logic as smartprs.com (SmartPRS 2 BillingController::priceFor + withSetup), new prices.
// Run: node tests/pricing_calc_test.js
const fs = require('fs'), path = require('path'), assert = require('assert');
const root = path.join(__dirname, '..');
const P = JSON.parse(fs.readFileSync(path.join(root, 'config/landing.json'), 'utf8')).pricing;
const gst = JSON.parse(fs.readFileSync(path.join(root, 'config/gst.json'), 'utf8')).rate_percent;
const { quote, bestFit } = require(path.join(root, 'public/js/pricing.js'));
let n = 0; const ok = (c, m) => { assert.ok(c, m); n++; };
const r2 = v => Math.round(v * 100) / 100;

// SmartPRS 2's formula, written out independently.
const sp2 = (plan, seats, months, companies) => {
    const disc = { 3: 0, 6: 0.10, 12: 0.25 }[months];
    const perMonth = plan.monthly + plan.extra * Math.max(0, seats - plan.employees) + 1000 * (companies - 1);
    const amount = r2(perMonth * months * (1 - disc)) + 2500 + 100 * seats;   // + setup (not discounted)
    const tax = r2(amount * 0.18);
    return r2(amount + tax);
};
const plans = Object.fromEntries(P.cloud.packages.map(p => [p.name, p]));
ok(plans.Starter.employees === 25 && plans.Starter.monthly === 1500 && plans.Starter.extra === 60, 'Starter 25 / 1,500 / +60');
ok(plans.Growth.employees === 50 && plans.Growth.monthly === 2500 && plans.Growth.extra === 50, 'Growth 50 / 2,500 / +50');
ok(plans.Professional.employees === 100 && plans.Professional.monthly === 4500 && plans.Professional.extra === 45, 'Professional 100 / 4,500 / +45');
for (const name of Object.keys(plans)) for (const seats of [1, 10, 25, 26, 40, 50, 51, 80, 100]) for (const months of [3, 6, 12]) for (const companies of [1, 2, 4]) {
    const q = quote(P, gst, { deployment: 'cloud', employees: seats, months, companies, plan: name });
    ok(q.total === sp2(plans[name], seats, months, companies), `${name} ${seats} emp ${months}m ${companies} co: ${q.total} vs ${sp2(plans[name], seats, months, companies)}`);
}
// Spot values a person can check by hand.
let q = quote(P, gst, { deployment: 'cloud', employees: 50, months: 3, companies: 1 });
ok(q.plan.name === 'Growth' && q.subtotal === 7500 + 7500 && q.gst === 2700 && q.total === 17700, 'Growth 50, quarterly: 7,500 + setup 7,500 + GST 2,700 = 17,700');
q = quote(P, gst, { deployment: 'cloud', employees: 25, months: 12, companies: 1 });
ok(q.subtotal === 13500 + 5000 && q.total === 21830, 'Starter 25, annual 25% off: 13,500 + 5,000 + GST = 21,830');
q = quote(P, gst, { deployment: 'cloud', employees: 30, months: 3, companies: 1, plan: 'Starter' });
ok(q.lines[1].amount === 300, 'Starter with 30 employees: 5 extra × ₹60 = ₹300/mo');
ok(bestFit(P, 10).name === 'Starter' && bestFit(P, 26).name === 'Growth' && bestFit(P, 51).name === 'Professional', 'best fit 10/26/51');
ok(quote(P, gst, { deployment: 'cloud', employees: 101 }).contact === true, 'above 100 → Contact Sales');
// On-premises.
const o = quote(P, gst, { deployment: 'onprem', employees: 50 });
ok(o.lines[0].amount === 350000 && o.lines[1].amount === 5000 && o.lines[2].amount === 7500, 'licence, installation, onboarding');
ok(o.subtotal === 362500 && o.gst === 65250 && o.total === 427750, 'on-prem 4,27,750');
ok(o.recurring.amount === 70000 && o.recurring.total === 82600, 'AMC 70,000 + GST = 82,600');
console.log(n + ' checks passed');
