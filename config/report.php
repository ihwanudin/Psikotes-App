<?php

declare(strict_types=1);

/*
 * Fixed publisher identity printed on every HPP report (Template HPP
 * v2.3 Bagian I.C): the psychology service unit's own name and address,
 * the same for every report regardless of which branch the participant
 * was assessed at. Confirmed by the user (Konfirmasi Akhir butir 7,
 * tasks/handoffs/f6/report-documents-schema-proposal.md:120) — not
 * environment-specific, so deliberately not read from env().
 */
return [
    'facility_name' => 'Unit Layanan Psikologi — PT Online Career Mentor',
    'facility_address' => 'Salatiga, Jawa Tengah, Indonesia',
];
