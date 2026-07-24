<?php

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'local/rtcsync:readmanagedstate' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
];
