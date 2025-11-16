<?php
// Simple endpoint test for DocuMed appointment API
header('Content-Type: application/json');
echo json_encode([
  'success' => true,
  'message' => 'API is reachable.'
]);
