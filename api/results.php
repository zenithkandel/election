<?php

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

$sourcePageUrl = "https://result.election.gov.np/PRVoteChartResult2082.aspx";
$dataUrl = "https://result.election.gov.np/Handlers/SecureJson.ashx?file=JSONFiles/Election2082/Common/PRHoRPartyTop5.txt";
$userAgent = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36";
$cookieFile = tempnam(sys_get_temp_dir(), "election_cookie_");

if ($cookieFile === false) {
    http_response_code(500);
    echo json_encode(["error" => "Unable to create a temporary cookie store."]);
    exit;
}

function respondWithError(int $statusCode, string $message, ?array $details = null): void
{
    http_response_code($statusCode);

    $payload = ["error" => $message];

    if ($details !== null) {
        $payload["details"] = $details;
    }

    echo json_encode($payload);
}

function createCurlHandle(string $url, string $cookieFile, string $userAgent)
{
    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_ENCODING => "",
        CURLOPT_USERAGENT => $userAgent,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    return $ch;
}

function parseCookiesFromJar(string $cookieFile): array
{
    if (!is_readable($cookieFile)) {
        return [];
    }

    $cookies = [];
    $lines = file($cookieFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if ($lines === false) {
        return [];
    }

    foreach ($lines as $line) {
        if ($line[0] === '#') {
            continue;
        }

        $parts = explode("\t", $line);

        if (count($parts) >= 7) {
            $cookies[$parts[5]] = $parts[6];
        }
    }

    return $cookies;
}

$bootstrapRequest = createCurlHandle($sourcePageUrl, $cookieFile, $userAgent);
curl_setopt_array($bootstrapRequest, [
    CURLOPT_HTTPHEADER => [
        "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
        "Accept-Language: en-US,en;q=0.6",
        "Cache-Control: no-cache",
        "Pragma: no-cache",
    ],
]);

$bootstrapResponse = curl_exec($bootstrapRequest);
$bootstrapStatus = (int) curl_getinfo($bootstrapRequest, CURLINFO_RESPONSE_CODE);

if ($bootstrapResponse === false) {
    $error = curl_error($bootstrapRequest);
    curl_close($bootstrapRequest);
    @unlink($cookieFile);
    respondWithError(502, "Unable to start the upstream session.", ["curl" => $error]);
    exit;
}

curl_close($bootstrapRequest);

if ($bootstrapStatus < 200 || $bootstrapStatus >= 300) {
    @unlink($cookieFile);
    respondWithError(502, "The upstream chart page did not return a successful response.", ["status" => $bootstrapStatus]);
    exit;
}

$cookies = parseCookiesFromJar($cookieFile);
$csrfToken = $cookies["CsrfToken"] ?? null;

if ($csrfToken === null || $csrfToken === "") {
    @unlink($cookieFile);
    respondWithError(502, "Unable to obtain the upstream CSRF token.");
    exit;
}

$dataRequest = createCurlHandle($dataUrl, $cookieFile, $userAgent);
curl_setopt_array($dataRequest, [
    CURLOPT_HTTPHEADER => [
        "Accept: application/json, text/javascript, */*; q=0.01",
        "Accept-Language: en-US,en;q=0.6",
        "Cache-Control: no-cache",
        "Pragma: no-cache",
        "Referer: https://result.election.gov.np/PRVoteChartResult2082.aspx",
        "X-CSRF-Token: {$csrfToken}",
        "X-Requested-With: XMLHttpRequest",
    ],
]);

$response = curl_exec($dataRequest);
$responseStatus = (int) curl_getinfo($dataRequest, CURLINFO_RESPONSE_CODE);

if ($response === false) {
    $error = curl_error($dataRequest);
    curl_close($dataRequest);
    @unlink($cookieFile);
    respondWithError(502, "Unable to fetch the upstream results.", ["curl" => $error]);
    exit;
}

curl_close($dataRequest);
@unlink($cookieFile);

if ($responseStatus < 200 || $responseStatus >= 300) {
    respondWithError(502, "The upstream results endpoint rejected the request.", ["status" => $responseStatus, "body" => $response]);
    exit;
}

$decoded = json_decode($response, true);

if (!is_array($decoded)) {
    respondWithError(502, "The upstream service returned a non-JSON response.", ["body" => $response]);
    exit;
}

echo json_encode($decoded);