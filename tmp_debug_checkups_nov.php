<?php
parse_str('action=debug_checkups&start=2025-11-01&end=2025-11-30', $_GET);
include __DIR__ . '/documed_pwa/backend/api/report.php';
