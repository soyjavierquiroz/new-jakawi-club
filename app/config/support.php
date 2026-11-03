<?php
return [
    // Private, explicit subject => CLUB UUID grants. Read on every request; revocation is immediate.
    'grants_path' => storage_path('app/private/support/superadmins.json'),
    'audit_path' => storage_path('app/private/support/audit.jsonl'),
];
