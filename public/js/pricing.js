/* SMARTPRS 3.0 PRICING CALCULATOR (owner, 12 Nov 2026) — the same logic as smartprs.com's signup (SmartPRS 2
 * BillingController::priceFor + SignupController::withSetup): per month = plan + extra employees x the plan's rate +
 * extra companies x company fee; x months; less the advance discount; + setup & onboarding (not discounted); + GST.
 * Figures come from config/landing.json "pricing" and the GST rate from config/gst.json. quote() is pure so
 * tests/pricing_calc_test.js checks the arithmetic. */
(function (root) {
    'use strict';

    var r2 = function (v) { return Math.round(v * 100) / 100; };

    function onboarding(P, n) {
        var o = P.onboarding || {}, base = +o.base || 0, per = (+o.per_employee || 0) * n;
        return o.mode === 'max' ? Math.max(base, per) : base + per;
    }

    function packs(P) { return (P.cloud.packages || []).slice().sort(function (a, b) { return a.employees - b.employees; }); }

    /** The smallest plan that covers n employees (null above the largest). */
    function bestFit(P, n) {
        var p = packs(P);
        for (var i = 0; i < p.length; i++) { if (n <= p[i].employees) { return p[i]; } }
        return null;
    }

    /**
     * opts: {deployment: 'cloud'|'onprem', employees, companies, months, plan (name; default best fit)}
     * → {deployment, employees, plan, contact, lines:[{label, amount}], subtotal, gst, total, recurring:{label, amount, gst, total}}
     */
    function quote(P, gstPercent, opts) {
        var n = Math.max(1, Math.floor(+opts.employees || 0)), g = (+gstPercent || 0) / 100, lines = [], recurring;

        if (opts.deployment === 'cloud') {
            var all = packs(P), largest = all[all.length - 1];
            if (n > largest.employees) {
                return { deployment: 'cloud', employees: n, plan: null, contact: true, lines: [], subtotal: 0, gst: 0, total: 0, recurring: null };
            }
            var plan = null;
            all.forEach(function (p) { if (p.name === opts.plan) { plan = p; } });
            plan = plan || bestFit(P, n);
            var cyc = null;
            (P.cloud.cycles || []).forEach(function (c) { if (+c.months === +opts.months) { cyc = c; } });
            cyc = cyc || P.cloud.cycles[0];
            var companies = Math.max(1, Math.floor(+opts.companies || 1)), extra = Math.max(0, n - plan.employees);
            var companyFee = (+P.cloud.company_fee || 0) * (companies - 1);
            var perMonth = +plan.monthly + (+plan.extra || 0) * extra + companyFee;
            var gross = perMonth * cyc.months, disc = r2(gross * (+cyc.discount || 0) / 100), setup = onboarding(P, n);
            lines.push({ label: plan.name + ' plan — up to ' + plan.employees + ' employees', amount: +plan.monthly, per: 1 });
            if (extra > 0) { lines.push({ label: extra + ' extra employee' + (extra > 1 ? 's' : '') + ' × ₹' + plan.extra + '/mo', amount: plan.extra * extra, per: 1 }); }
            if (companyFee > 0) { lines.push({ label: (companies - 1) + ' extra compan' + (companies > 2 ? 'ies' : 'y') + ' × ₹' + (+P.cloud.company_fee).toLocaleString('en-IN') + '/mo', amount: companyFee, per: 1 }); }
            lines.push({ label: 'Billing period — ' + cyc.months + ' months × ' + inr(perMonth), amount: r2(gross), kind: 'sum' });
            if (disc > 0) { lines.push({ label: cyc.label + ' advance discount — ' + cyc.discount + '%', amount: -disc }); }
            lines.push({ label: 'Setup & onboarding (one time)', amount: setup });
            var sub = r2(gross - disc + setup), tax = r2(sub * g), period = r2(gross - disc);
            return { deployment: 'cloud', employees: n, plan: plan, cycle: cyc, contact: false, lines: lines, subtotal: sub, gst: tax, total: r2(sub + tax),
                recurring: { label: 'Each renewal (' + cyc.months + ' months' + (+cyc.discount ? ', ' + cyc.discount + '% off' : '') + ')', amount: period, gst: r2(period * g), total: r2(period + r2(period * g)) } };
        }

        var o = P.onprem, amc = Math.round(o.licence * o.amc_percent / 100);
        lines.push({ label: 'Lifetime licence (one time)', amount: +o.licence });
        lines.push({ label: 'Installation (one time)', amount: +o.installation });
        lines.push({ label: 'Setup & onboarding (one time)', amount: onboarding(P, n) });
        var s2 = lines.reduce(function (s, l) { return s + l.amount; }, 0), t2 = r2(s2 * g);
        recurring = { label: 'Annual AMC (' + o.amc_percent + '% of licence)' + (+o.amc_first_year_included ? ' — from year 2' : ' — every year'), amount: amc, gst: r2(amc * g), total: r2(amc + r2(amc * g)) };
        return { deployment: 'onprem', employees: n, plan: null, contact: false, lines: lines, subtotal: s2, gst: t2, total: r2(s2 + t2), recurring: recurring };
    }

    function inr(v) { return (v < 0 ? '− ' : '') + '₹' + Math.abs(v).toLocaleString('en-IN', { maximumFractionDigits: 2 }); }

    root.SmartPRSPricing = { quote: quote, onboarding: onboarding, bestFit: bestFit, inr: inr };
    if (typeof module !== 'undefined') { module.exports = root.SmartPRSPricing; }
    if (typeof document === 'undefined') { return; }

    document.addEventListener('DOMContentLoaded', function () {
        var box = document.getElementById('pricing'), data = document.getElementById('pricing-data');
        if (!box || !data) { return; }
        var P = JSON.parse(data.textContent), gstP = +box.getAttribute('data-gst');
        var st = { deployment: P.default === 'cloud' ? 'cloud' : 'onprem', plan: '', months: P.cloud.cycles[0].months };
        var emp = document.getElementById('calc-emp'), co = document.getElementById('calc-co'), out = document.getElementById('calc-out');
        var on = function (sel, attr, val) { box.querySelectorAll(sel).forEach(function (b) { b.classList.toggle('on', b.getAttribute(attr) === String(val)); }); };

        function show(d) {
            st.deployment = d;
            on('[data-dep]', 'data-dep', d);
            box.querySelectorAll('[data-panel]').forEach(function (p) { p.hidden = p.getAttribute('data-panel') !== d; });
            box.querySelectorAll('[data-cloud-only]').forEach(function (p) { p.hidden = d !== 'cloud'; });
            calc();
        }

        function calc() {
            var q = quote(P, gstP, { deployment: st.deployment, employees: emp.value, companies: co.value, months: st.months, plan: st.plan }), h = '';
            on('[data-plan]', 'data-plan', q.plan ? q.plan.name : '');
            on('[data-months]', 'data-months', st.months);
            h += '<div class="cq-row cq-head"><span>Deployment</span><b>' + (q.deployment === 'cloud' ? 'Cloud Service' : 'On-Premises (lifetime licence)') + '</b></div>';
            h += '<div class="cq-row"><span>Employees</span><b>' + q.employees + '</b></div>';
            if (q.contact) {
                out.innerHTML = h + '<div class="cq-contact">' + P.cloud.above_label + ' — we price your package with you. <a href="#contact" class="btn btn-accent">' + P.cloud.above_text + '</a></div>';
                return;
            }
            q.lines.forEach(function (l) { h += '<div class="cq-row' + (l.kind ? ' cq-' + l.kind : '') + '"><span>' + l.label + '</span><b>' + inr(l.amount) + (l.per ? '/mo' : '') + '</b></div>'; });
            h += '<div class="cq-row cq-sub"><span>Subtotal</span><b>' + inr(q.subtotal) + '</b></div>';
            h += '<div class="cq-row"><span>GST @ ' + gstP + '%</span><b>' + inr(q.gst) + '</b></div>';
            h += '<div class="cq-row cq-total"><span>Payable now</span><b>' + inr(q.total) + '</b></div>';
            h += '<div class="cq-rec"><span>' + q.recurring.label + '</span><b>' + inr(q.recurring.amount) + ' + GST ' + inr(q.recurring.gst) + ' = ' + inr(q.recurring.total) + '</b></div>';
            out.innerHTML = h;
        }

        box.querySelectorAll('[data-dep]').forEach(function (b) { b.addEventListener('click', function () { show(b.getAttribute('data-dep')); }); });
        box.querySelectorAll('[data-plan]').forEach(function (b) { b.addEventListener('click', function () { st.plan = b.getAttribute('data-plan'); calc(); }); });
        box.querySelectorAll('[data-months]').forEach(function (b) { b.addEventListener('click', function () { st.months = +b.getAttribute('data-months'); calc(); }); });
        box.querySelectorAll('[data-pick]').forEach(function (b) {
            b.addEventListener('click', function () {
                st.plan = b.getAttribute('data-pick');
                P.cloud.packages.forEach(function (x) { if (x.name === st.plan && (+emp.value > x.employees || +emp.value < 1)) { emp.value = x.employees; } });
                show('cloud');
                document.getElementById('calc').scrollIntoView({ behavior: 'smooth', block: 'center' });
            });
        });
        emp.addEventListener('input', function () { st.plan = ''; calc(); });
        co.addEventListener('input', calc);
        show(st.deployment);
    });
})(typeof window !== 'undefined' ? window : globalThis);
