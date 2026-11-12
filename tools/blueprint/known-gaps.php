<?php

declare(strict_types=1);

/**
 * THE BLUEPRINT EXPRESSIONS THAT DO NOT YET COMPILE, AND WHY.
 *
 * A ratchet, not an allow-list. compile-worked-rules.php fails when anything
 * NOT listed here fails, AND when anything listed here starts passing without
 * being removed. The count can only go down, and a stale entry is as loud as a
 * new failure — which is the property an ordinary allow-list does not have.
 *
 * IT IS EMPTY. Every worked rule and formula in the documents — 48 expressions
 * from 38 artefacts, Part VII §12's Section 127 acceptance test among them —
 * parses and type-checks against the engine that runs them.
 *
 * It started at sixteen. What came out of emptying it is worth keeping, because
 * the same shape recurred and will recur:
 *
 *   FOUR keyed accessors had a type that depends on their key, and nothing
 *   declared the key. policy.value() was Number for every policy, event.value
 *   was Number for every metric, statutory.value() was Money for every code,
 *   and formula.value() had no type at all. Each made the unit system stop
 *   exactly where configuration begins — which is where SmartPRS 2's unit
 *   confusion actually lives. PolicyKeys, AggMetrics, StatutoryKeys and
 *   FormulaRegistry close them.
 *
 *   TWO were the engine contradicting something already written down.
 *   period.working_days was a Number while payable_days was Days, so no
 *   proration factor could be dimensionless. TO_DAYS refused a Number while the
 *   coercion table's own docblock named TO_DAYS as the sanctioned Number → Days
 *   conversion.
 *
 *   TWO were checks in the wrong place. BANDS wanted two inline lists where the
 *   document maintains one configured table; SLAB demanded a literal table code
 *   so it could validate the bands at formula publish, when the professional-tax
 *   table is chosen per employee by their establishment's state and sixteen
 *   state tables are pack content validated at pack signing.
 *
 *   ONE was a wrong guess of mine, caught by a formula. comm_accelerator_points
 *   was INFERRED as a code list from its name, and is a number of percent.
 *
 * A NEW ENTRY IS NOT A FAILURE. Add one when the blueprint asks for something
 * the language does not offer and the right answer is a decision rather than a
 * fix — with what the decision is, not just what broke. Then take it, and delete
 * the entry. That is what this file is for; an empty one is the steady state,
 * not the finished state.
 *
 * Key: '<ARTEFACT CODE> <clause>'.
 */
return [];
