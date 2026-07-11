<?php

/**
 * validate_api.php is a demo script for validating COUNTER API requests and responses
 *
 * @author Bernd Oberknapp <bo@ub.uni-freiburg.de>
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 * @copyright 2019-2025 Albert-Ludwigs-Universität, Universitätsbibliothek
 */

require __DIR__ . '/../vendor/autoload.php';

$options = getopt('u:d::j::m::t::x::');
if (! isset($options['u']) || (isset($options['x']) && empty($options['x']))) {
    print <<<EOS
usage: php {$argv[0]} -u<url> [-d] [-j] [-m] [-t] [-x<filename>]
Validate a COUNTER R5 or R5.1 API request and response.

Options:
  -u<url>      COUNTER API URL to validate
  -d           debug - show internal representation
  -j           show JSON
  -m           show memory used
  -t           show time used
  -x<filename> save validation report as Excel file

EOS;
    exit(5);
}

$url = $options['u'];
$debug = isset($options['d']);
$showJson = isset($options['j']);
$showMemory = isset($options['m']);
$showTime = isset($options['t']);
$xlsxResult = isset($options['x']);

$startTime = microtime(true);
try {
    $request = \ubfr\c5tools\CounterApiRequest::fromUrl($url);
    $response = $request->doRequest();
    if ($response->isException() || $response->isReportWithException()) {
        throw new \ubfr\c5tools\exceptions\CounterApiException($response, $request);
    } elseif ($response->isMemberList()) {
        $response = new \ubfr\c5tools\MemberList($response, $request);
    } elseif ($response->isReportList()) {
        $response = new \ubfr\c5tools\ReportList($response, $request);
    } elseif ($response->isStatusList()) {
        $response = new \ubfr\c5tools\StatusList($response, $request);
    } elseif ($response->isReport()) {
        $response = new \ubfr\c5tools\JsonReport($response, $request);
    } else {
        throw new \ubfr\c5tools\exceptions\InvalidCounterApiResponseException(
            'response is unusuable',
            $request->getRequestUrl(),
            $response->getHttpResponse()
        );
    }

    print_result($response);
} catch (\ubfr\c5tools\exceptions\CounterApiRequestException $e) {
    if (isset($request)) {
        print("URL: " . $request->getRequestUrl() . "\n");
    }
    print('RESULT: ' . get_class($e) . "\n");
    print('Code: ' . $e->getCode() . "\n");
    print('Message: ' . $e->getMessage() . "\n");
} catch (\ubfr\c5tools\exceptions\InvalidCounterApiResponseException $e) {
    print("URL: " . $request->getRequestUrl() . "\n");
    print("BODY:\n" . $e->getResponse()->getBody() . "\n");
    print('HTTP-Code: ' . $e->getResponse()->getStatusCode() . "\n");
    print('RESULT: ' . get_class($e) . "\n");
    print('Code: ' . $e->getCode() . "\n");
    print('Message: ' . $e->getMessage() . "\n");
} catch (\ubfr\c5tools\exceptions\CounterApiException $e) {
    print("URL: " . $request->getRequestUrl() . "\n");
    print_result($e);
    print('Code: ' . $e->getCode() . "\n");
    print('Message: ' . $e->getMessage() . "\n");
}

function print_result($response)
{
    global $debug, $options, $showJson, $showMemory, $showTime, $startTime, $xlsxResult;

    $checkResult = $response->getCheckResult();
    if ($xlsxResult) {
        print('Saving validation report in ' . $options['x'] . "\n");
        $checkResult->saveXlsx($options['x'], 0);
    } else {
        print $checkResult->asText(0);
    }
    if ($showTime) {
        $endTime = microtime(true);
        printf("\nTime: %.2f s\n", $endTime - $startTime);
    }
    if ($showMemory) {
        printf("\nMemory: %.2f MB\n", memory_get_peak_usage(true) / 1024 / 1024);
    }
    if ($response instanceof \ubfr\c5tools\JsonReport) {
        print("\nReport is " . ($response->isUsable() ? "usable" : "unusable") . "\n");
    }
    if ($debug) {
        print "\n";
        $response->debug();
    }
    if ($showJson) {
        $json = json_decode($response->getJsonString());
        if ($json !== null) {
            print("\n");
            print(json_encode($json, JSON_PRETTY_PRINT));
            print("\n");
        }
    }
}
