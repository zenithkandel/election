<?php

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

$userAgent = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36";
$caBundle = ini_get("curl.cainfo") ?: ini_get("openssl.cafile");
$shouldVerifyTls = $caBundle !== false && $caBundle !== "";

$sources = [
    "pr" => [
        "sourcePageUrl" => "https://result.election.gov.np/PRVoteChartResult2082.aspx",
        "dataUrl" => "https://result.election.gov.np/Handlers/SecureJson.ashx?file=JSONFiles/Election2082/Common/PRHoRPartyTop5.txt",
        "referer" => "https://result.election.gov.np/PRVoteChartResult2082.aspx",
        "seatCount" => 110,
    ],
    "fptp" => [
        "sourcePageUrl" => "https://result.election.gov.np/FPTPWLChartResult2082.aspx",
        "dataUrl" => "https://result.election.gov.np/Handlers/SecureJson.ashx?file=JSONFiles/Election2082/Common/HoRPartyTop5.txt",
        "referer" => "https://result.election.gov.np/FPTPWLChartResult2082.aspx",
        "seatCount" => 165,
    ],
];

function respondWithError(int $statusCode, string $message, ?array $details = null): void
{
    http_response_code($statusCode);

    $payload = ["error" => $message];

    if ($details !== null) {
        $payload["details"] = $details;
    }

    echo json_encode($payload);
}

function createCurlHandle(string $url, string $cookieFile, string $userAgent, bool $shouldVerifyTls, string $caBundle = "")
{
    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_ENCODING => "",
        CURLOPT_USERAGENT => $userAgent,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_SSL_VERIFYPEER => $shouldVerifyTls,
        CURLOPT_SSL_VERIFYHOST => $shouldVerifyTls ? 2 : 0,
    ]);

    if ($shouldVerifyTls && $caBundle !== "") {
        curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
    }

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
        if ($line === "" || $line[0] === '#') {
            continue;
        }

        $parts = explode("\t", $line);

        if (count($parts) >= 7) {
            $cookies[$parts[5]] = $parts[6];
        }
    }

    return $cookies;
}

function parseCookiesFromHeaders(string $rawHeaders): array
{
    $cookies = [];
    $headerLines = preg_split('/\r\n|\r|\n/', $rawHeaders) ?: [];

    foreach ($headerLines as $line) {
        if (stripos($line, 'Set-Cookie:') !== 0) {
            continue;
        }

        $cookiePair = trim(substr($line, strlen('Set-Cookie:')));
        $segments = explode(';', $cookiePair);
        $nameValue = explode('=', trim($segments[0]), 2);

        if (count($nameValue) === 2) {
            $cookies[$nameValue[0]] = $nameValue[1];
        }
    }

    return $cookies;
}

function normalizePrRows(array $rows, int $seatCount): array
{
    $totalVotes = array_reduce($rows, static function (int $sum, array $row): int {
        return $sum + (int) ($row["TotalVoteReceived"] ?? 0);
    }, 0);

    $normalized = [];

    foreach ($rows as $row) {
        $votes = (int) ($row["TotalVoteReceived"] ?? 0);
        $voteShare = $totalVotes > 0 ? $votes / $totalVotes : 0;

        $normalized[] = [
            "partyName" => (string) ($row["PoliticalPartyName"] ?? "Unknown Party"),
            "symbolId" => (int) ($row["SymbolID"] ?? 0),
            "prVotes" => $votes,
            "prVoteShare" => $voteShare,
            "prEstimatedSeats" => (int) round($voteShare * $seatCount),
        ];
    }

    return $normalized;
}

function normalizeFptpRows(array $rows): array
{
    $normalized = [];

    foreach ($rows as $row) {
        $won = (int) ($row["TotWin"] ?? 0);
        $leading = (int) ($row["TotLead"] ?? 0);
        $candidates = (int) ($row["t_cand"] ?? 0);

        $normalized[] = [
            "partyName" => (string) ($row["PoliticalPartyName"] ?? "Unknown Party"),
            "symbolId" => (int) ($row["SymbolID"] ?? 0),
            "fptpWon" => $won,
            "fptpLeading" => $leading,
            "fptpProjectedSeats" => $won + $leading,
            "candidateCount" => $candidates,
        ];
    }

    return $normalized;
}

function fetchElectionDataset(array $config, string $userAgent, bool $shouldVerifyTls, string $caBundle): array
{
    $cookieFile = tempnam(sys_get_temp_dir(), "election_cookie_");

    if ($cookieFile === false) {
        throw new RuntimeException("Unable to create a temporary cookie store.");
    }

    try {
        $bootstrapRequest = createCurlHandle($config["sourcePageUrl"], $cookieFile, $userAgent, $shouldVerifyTls, $caBundle);
        curl_setopt_array($bootstrapRequest, [
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => [
                "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
                "Accept-Language: en-US,en;q=0.6",
                "Cache-Control: no-cache",
                "Pragma: no-cache",
            ],
        ]);

        $bootstrapResponse = curl_exec($bootstrapRequest);
        $bootstrapStatus = (int) curl_getinfo($bootstrapRequest, CURLINFO_RESPONSE_CODE);
        $bootstrapHeaderSize = (int) curl_getinfo($bootstrapRequest, CURLINFO_HEADER_SIZE);

        if ($bootstrapResponse === false) {
            $error = curl_error($bootstrapRequest);
            curl_close($bootstrapRequest);
            throw new RuntimeException("Unable to start the upstream session.", 0, new RuntimeException($error));
        }

        curl_close($bootstrapRequest);

        if ($bootstrapStatus < 200 || $bootstrapStatus >= 300) {
            throw new RuntimeException("The upstream chart page did not return a successful response.");
        }

        $bootstrapHeaders = substr($bootstrapResponse, 0, $bootstrapHeaderSize);
        $cookies = parseCookiesFromHeaders($bootstrapHeaders);

        if ($cookies === []) {
            $cookies = parseCookiesFromJar($cookieFile);
        }

        $csrfToken = $cookies["CsrfToken"] ?? null;
        $sessionId = $cookies["ASP.NET_SessionId"] ?? null;

        if ($csrfToken === null || $csrfToken === "" || $sessionId === null || $sessionId === "") {
            throw new RuntimeException("Unable to obtain the upstream session cookies.");
        }

        $cookieHeader = sprintf("ASP.NET_SessionId=%s; CsrfToken=%s", $sessionId, $csrfToken);

        $dataRequest = createCurlHandle($config["dataUrl"], $cookieFile, $userAgent, $shouldVerifyTls, $caBundle);
        curl_setopt_array($dataRequest, [
            CURLOPT_HTTPHEADER => [
                "Accept: application/json, text/javascript, */*; q=0.01",
                "Accept-Language: en-US,en;q=0.6",
                "Cache-Control: no-cache",
                "Pragma: no-cache",
                "Cookie: {$cookieHeader}",
                "Referer: {$config['referer']}",
                "X-CSRF-Token: {$csrfToken}",
                "X-Requested-With: XMLHttpRequest",
            ],
        ]);

        $response = curl_exec($dataRequest);
        $responseStatus = (int) curl_getinfo($dataRequest, CURLINFO_RESPONSE_CODE);

        if ($response === false) {
            $error = curl_error($dataRequest);
            curl_close($dataRequest);
            throw new RuntimeException("Unable to fetch the upstream results.", 0, new RuntimeException($error));
        }

        curl_close($dataRequest);

        if ($responseStatus < 200 || $responseStatus >= 300) {
            throw new RuntimeException("The upstream results endpoint rejected the request.");
        }

        $decoded = json_decode($response, true);

        if (!is_array($decoded)) {
            throw new RuntimeException("The upstream service returned a non-JSON response.");
        }

        return $decoded;
    } finally {
        @unlink($cookieFile);
    }
}

try {
    $prRows = fetchElectionDataset($sources["pr"], $userAgent, $shouldVerifyTls, (string) $caBundle);
    $fptpRows = fetchElectionDataset($sources["fptp"], $userAgent, $shouldVerifyTls, (string) $caBundle);

    echo json_encode([
        "pr" => normalizePrRows($prRows, $sources["pr"]["seatCount"]),
        "fptp" => normalizeFptpRows($fptpRows),
        "meta" => [
            "prSeats" => $sources["pr"]["seatCount"],
            "fptpSeats" => $sources["fptp"]["seatCount"],
            "totalSeats" => $sources["pr"]["seatCount"] + $sources["fptp"]["seatCount"],
            "generatedAt" => gmdate(DATE_ATOM),
        ],
    ]);
} catch (Throwable $exception) {
    $details = ["message" => $exception->getMessage()];
    $previous = $exception->getPrevious();

    if ($previous instanceof Throwable) {
        $details["previous"] = $previous->getMessage();
    }

    respondWithError(502, "Unable to load election data.", $details);
}