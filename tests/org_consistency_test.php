<?php

declare(strict_types=1);

use SmartPRS\Surface\OrgConsistency;
use SmartPRS\Surface\Store;

/** 28 Sep: an employee's company, branch, department, designation and team must belong together. */
group('org consistency');

$s = (new Store(sys_get_temp_dir() . '/prs3-oc-' . bin2hex(random_bytes(4))))->forTenant('1');
$s->put('branches', ['id' => 'B1', 'name' => 'Kondapur', 'employer_company_id' => 'C1']);
$s->put('branches', ['id' => 'B2', 'name' => 'Andheri', 'employer_company_id' => 'C2']);
$s->put('departments', ['id' => 'D1', 'name' => 'Ops', 'branch_id' => 'B1']);
$s->put('designations', ['id' => 'G1', 'name' => 'Analyst', 'department_id' => 'D1']);
$s->put('teams', ['id' => 'T2', 'name' => 'Recovery', 'employer_company_id' => 'C2']);
$s->put('departments', ['id' => 'D0', 'name' => 'Anywhere']);

same([], OrgConsistency::problems($s, ['employer_company_id' => 'C1', 'branch_id' => 'B1', 'department_id' => 'D1', 'designation_id' => 'G1']), 'a consistent employee passes');
same(1, count(OrgConsistency::problems($s, ['employer_company_id' => 'C1', 'branch_id' => 'B2'])), 'another company\'s branch is refused');
same(1, count(OrgConsistency::problems($s, ['employer_company_id' => 'C2', 'branch_id' => 'B2', 'department_id' => 'D1'])), 'another branch\'s department is refused');
same(1, count(OrgConsistency::problems($s, ['employer_company_id' => 'C1', 'team_id' => 'T2'])), 'another company\'s team is refused');
same([], OrgConsistency::problems($s, ['employer_company_id' => 'C2', 'branch_id' => 'B2', 'department_id' => 'D0']), 'a unit with no parent recorded fits anywhere');
