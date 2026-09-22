<?php

declare(strict_types=1);

return [
    // How many attempts a participant gets automatically, with no admin
    // approval at all (item 19, tasks/handoffs/f2/retest-limit-plan.md).
    // Every attempt past this needs an individual, audited grant from
    // super_admin/central_admin/psychologist -- with no upper bound beyond
    // that (owner's explicit decision: repeated human approval is the
    // deterrent, not a fixed ceiling).
    'free_attempt_limit' => (int) env('ASSESSMENT_RETEST_FREE_ATTEMPT_LIMIT', 3),
];
