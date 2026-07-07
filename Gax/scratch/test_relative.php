<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Google\ApiCore\Transport\RestTransport;
use GuzzleHttp\Psr7\Request;

// Construct a RestTransport
$transport = RestTransport::build('storage.googleapis.com', __DIR__ . '/../tests/Unit/testdata/resources/test_service_rest_client_config.php');

// Send a relative request
$request = new Request('GET', '/storage/v1/b');
try {
    $transport->sendRequest($request)->wait();
} catch (\Exception $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
}
