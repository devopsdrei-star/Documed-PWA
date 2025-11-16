<?php
// Simulate a POST request to chatbot_deepseek.php
$_SERVER['REQUEST_METHOD'] = 'POST';
$payload = ['message' => $argv[1] ?? 'What are your hours?'];
$json = json_encode($payload);

// Provide php://input for the included file
file_put_contents('php://memory', $json);

// PHP's php://input is not writable; instead, we create a temp stream wrapper
// Use a temporary file and set STDIN
$tmp = sys_get_temp_dir() . '/chatbot_input_' . uniqid() . '.json';
file_put_contents($tmp, $json);
// Make php://input read from the temp file by using a stream wrapper trick isn't trivial here.
// Instead, set $HTTP_RAW_POST_DATA or use a small include wrapper that reads from a global.

// We'll include a small shim: set a global that chatbot_deepseek.php will read if present.
$GLOBALS['__TEST_POST_JSON'] = $json;

// To make the existing file use the test body, we'll create a small wrapper that
// temporarily modifies file_get_contents('php://input') by using runkit isn't available.
// Instead, we'll copy chatbot_deepseek.php, replace file_get_contents('php://input')
// calls with the test JSON for this CLI test only.

$orig = __DIR__ . '/chatbot_deepseek.php';
$copy = __DIR__ . '/chatbot_deepseek_cli_wrapper.php';
$code = file_get_contents($orig);
$code = str_replace("file_get_contents('php://input')", "\$GLOBALS['__TEST_POST_JSON']", $code);
file_put_contents($copy, $code);

// Run the wrapper and capture output
passthru('php ' . escapeshellarg($copy), $exitCode);

// Clean up
@unlink($copy);
@unlink($tmp);

exit($exitCode);
