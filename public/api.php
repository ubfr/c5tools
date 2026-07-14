<?php

require __DIR__ . '/../vendor/autoload.php';

// Define log file path
define('API_LOG_FILE', __DIR__ . '/../logs/api.log');

// Ensure logs directory exists
if (!file_exists(dirname(API_LOG_FILE))) {
    mkdir(dirname(API_LOG_FILE), 0755, true);
}

/**
 * Log an exception using PHP's error logging
 * @param Exception $e The exception to log
 * @param array $context Additional context data
 */
function logApiException(Exception $e, array $context = []): void
{
    $timestamp = date('Y-m-d H:i:s');
    $message = sprintf(
        "[%s] Exception: %s\nCode: %d\nFile: %s\nLine: %d\nTrace:\n%s\nContext: %s\n",
        $timestamp,
        $e->getMessage(),
        $e->getCode(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString(),
        json_encode($context, JSON_PRETTY_PRINT)
    );
    error_log($message);
}

/**
 * Log a successful COUNTER API request using PHP's error logging
 * @param string $url The request URL
 * @param object $response The response object
 * @param array $context Additional context data
 */
function logApiSuccess(string $url, object $response, array $context = []): void
{
    $timestamp = date('Y-m-d H:i:s');
    $responseType = get_class($response);
    $message = sprintf(
        "[%s] Successful COUNTER API Request\nURL: %s\nResponse Type: %s\nContext: %s\n",
        $timestamp,
        $url,
        $responseType,
        json_encode($context, JSON_PRETTY_PRINT)
    );
    error_log($message);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit();
}

// parse post content as json
$post = json_decode(file_get_contents('php://input'), true);
if (json_last_error() != JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode([
        "error" => "invalid json in request body",
    ]);
    exit();
}
// check if url is set
if (!isset($post["url"])) {
    http_response_code(400);
    echo json_encode([
        "error" => "'url' is missing in POST data",
    ]);
    exit();
}

$url = $post["url"];
$response = null;

try {
    $request = \ubfr\c5tools\CounterApiRequest::fromUrl($url);
    $response = $request->doRequest();
    if ($response->isException()) {
        $response = new \ubfr\c5tools\exceptions\CounterApiException($response, $request);
    } elseif ($response->isMemberList()) {
        $response = new \ubfr\c5tools\MemberList($response, $request);
    } elseif ($response->isReportList()) {
        $response = new \ubfr\c5tools\ReportList($response, $request);
    } elseif ($response->isStatusList()) {
        $response = new \ubfr\c5tools\StatusList($response, $request);
    } elseif ($response->isReport()) {
        $response = new \ubfr\c5tools\JsonReport($response, $request);
    } else {
        // print out URL and response
        $stderr = fopen('php://stderr', 'w');
        fwrite($stderr, "URL: " . $request->getRequestUrl() . "\n");
        fwrite($stderr, "Status code: " . $response->getHttpResponse()->getStatusCode() . "\n");
        fwrite($stderr, "Response: " . $response->getHttpResponse()->getBody() . "\n");
        fclose($stderr);
        $message = "Validation module got an unexpected response: Code " .
            $response->getHttpResponse()->getStatusCode() . "; Body: " .
            substr($response->getHttpResponse()->getBody(), 0, 200);
        throw new \ubfr\c5tools\exceptions\InvalidCounterApiResponseException(
            $message,
            $request->getRequestUrl(),
            $response->getHttpResponse()
        );
    }

    $checkResult = $response->getCheckResult();

    // Log successful request
    logApiSuccess($url, $response, [
        'request_method' => $_SERVER['REQUEST_METHOD'],
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
        'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
        'memory_usage' => memory_get_peak_usage(true)
    ]);
} catch (Exception $e) {
    // Log the exception with context
    logApiException($e, [
        'url' => $url,
        'request_method' => $_SERVER['REQUEST_METHOD'],
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
        'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown'
    ]);

    $checkResult = new \ubfr\c5tools\CheckResult();
    $checkResult->addFatalError($e->getMessage(), $e->getMessage());
}

$file = null;
if ($response instanceof \ubfr\c5tools\interfaces\JsonDocument) {
    $json_file = $response->getJsonString();
    if ($json_file) {
        $compressed = gzcompress($json_file, 3);
        if ($compressed) {
            $file = base64_encode($compressed);
        }
    }
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    "memory" => memory_get_peak_usage(true),
    "result" => json_decode($checkResult->asJson()),
    "report" => $file,
]);
