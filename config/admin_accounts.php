<?php

declare(strict_types=1);

return [
    // How long a one-time admin set-password link stays valid. Short by
    // design (Lead's ask, 2026-09-22): this link sets the password for a
    // super_admin/psychologist/branch_admin/staff account, a materially
    // more sensitive action than the 168-hour default used for a
    // participant's assessment invitation link.
    'password_setup_token_ttl_minutes' => (int) env('ADMIN_PASSWORD_SETUP_TOKEN_TTL_MINUTES', 60),

    // Email-OTP MFA code lifetime (tasks/handoffs/f2/admin-mfa-plan.md).
    // Filament's own default is 4 minutes; kept separately configurable
    // rather than hardcoded in the provider registration call.
    'mfa_code_expiry_minutes' => (int) env('ADMIN_MFA_CODE_EXPIRY_MINUTES', 5),
];
