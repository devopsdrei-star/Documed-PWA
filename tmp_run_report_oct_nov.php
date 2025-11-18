<?php
parse_str('action=clinic_overview&start=2025-10-01&end=2025-11-30', $_GET);
include __DIR__ . '/documed_pwa/backend/api/report.php';
