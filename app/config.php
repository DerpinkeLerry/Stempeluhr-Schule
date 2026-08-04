<?php
declare(strict_types=1);

return [
    'app_name' => 'Wepro Zeiterfassung',
    'database_file' => __DIR__ . '/../data/stempeluhr.sqlite',
    'default_timezone' => 'Europe/Berlin',
    'default_holiday_region' => 'DE-BY-KF',
    'default_vacation_entitlement' => 30,
    'seed_demo_users' => false,
    'session_name' => 'stempeluhr_schule',
];
