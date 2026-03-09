<?php

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

$userAgent = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36";
$caBundle = ini_get("curl.cainfo") ?: ini_get("openssl.cafile");
$shouldVerifyTls = $caBundle !== false && $caBundle !== "";

$sources = [
    "pr" => [
        "sourcePageUrl" => "https://result.election.gov.np/PRVoteChartResult2082.aspx",
        "referer" => "https://result.election.gov.np/PRVoteChartResult2082.aspx",
        "dataFile" => "JSONFiles/Election2082/Common/PRHoRPartyTop5.txt",
        "seatCount" => 110,
    ],
    "fptp" => [
        "sourcePageUrl" => "https://result.election.gov.np/FPTPWLChartResult2082.aspx",
        "referer" => "https://result.election.gov.np/FPTPWLChartResult2082.aspx",
        "dataFile" => "JSONFiles/Election2082/Common/HoRPartyTop5.txt",
        "seatCount" => 165,
    ],
    "map" => [
        "sourcePageUrl" => "https://result.election.gov.np/FPTPWLChartResult2082.aspx",
        "referer" => "https://result.election.gov.np/FPTPWLChartResult2082.aspx",
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

function respondWithJson(array $payload): void
{
    echo json_encode($payload);
}

function getCachePath(string $key): string
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . "election_cache_" . md5($key) . ".json";
}

function readCache(string $key, int $ttlSeconds): ?array
{
    $cachePath = getCachePath($key);

    if (!is_file($cachePath)) {
        return null;
    }

    if ((time() - filemtime($cachePath)) > $ttlSeconds) {
        return null;
    }

    $content = file_get_contents($cachePath);

    if ($content === false) {
        return null;
    }

    $decoded = json_decode($content, true);

    return is_array($decoded) ? $decoded : null;
}

function writeCache(string $key, array $payload): void
{
    @file_put_contents(getCachePath($key), json_encode($payload));
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
        if (stripos($line, "Set-Cookie:") !== 0) {
            continue;
        }

        $cookiePair = trim(substr($line, strlen("Set-Cookie:")));
        $segments = explode(";", $cookiePair);
        $nameValue = explode("=", trim($segments[0]), 2);

        if (count($nameValue) === 2) {
            $cookies[$nameValue[0]] = $nameValue[1];
        }
    }

    return $cookies;
}

function withSecureSession(array $config, string $userAgent, bool $shouldVerifyTls, string $caBundle, callable $callback)
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

        $session = [
            "cookieFile" => $cookieFile,
            "cookieHeader" => sprintf("ASP.NET_SessionId=%s; CsrfToken=%s", $sessionId, $csrfToken),
            "csrfToken" => $csrfToken,
            "referer" => $config["referer"] ?? $config["sourcePageUrl"],
            "userAgent" => $userAgent,
            "shouldVerifyTls" => $shouldVerifyTls,
            "caBundle" => $caBundle,
        ];

        return $callback($session);
    } finally {
        @unlink($cookieFile);
    }
}

function createSecureJsonHandle(array $session, string $filePath)
{
    $url = "https://result.election.gov.np/Handlers/SecureJson.ashx?file=" . $filePath;
    $ch = createCurlHandle($url, $session["cookieFile"], $session["userAgent"], $session["shouldVerifyTls"], $session["caBundle"]);

    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Accept: application/json, text/javascript, */*; q=0.01",
        "Accept-Language: en-US,en;q=0.6",
        "Cache-Control: no-cache",
        "Pragma: no-cache",
        "Cookie: {$session['cookieHeader']}",
        "Referer: {$session['referer']}",
        "X-CSRF-Token: {$session['csrfToken']}",
        "X-Requested-With: XMLHttpRequest",
    ]);

    return $ch;
}

function decodeJsonResponse(string $body, string $filePath): array
{
    $decoded = json_decode($body, true);

    if (!is_array($decoded)) {
        throw new RuntimeException("The upstream service returned a non-JSON response for {$filePath}.");
    }

    return $decoded;
}

function fetchSecureJsonFile(array $session, string $filePath): array
{
    $handle = createSecureJsonHandle($session, $filePath);
    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

    if ($response === false) {
        $error = curl_error($handle);
        curl_close($handle);
        throw new RuntimeException("Unable to fetch {$filePath}.", 0, new RuntimeException($error));
    }

    curl_close($handle);

    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("The upstream endpoint rejected {$filePath}.");
    }

    return decodeJsonResponse($response, $filePath);
}

function fetchSecureJsonBatch(array $session, array $filePaths, int $concurrency = 12): array
{
    $results = [];

    if ($filePaths === []) {
        return $results;
    }

    $queue = array_values($filePaths);
    $multi = curl_multi_init();
    $active = [];

    $addNext = static function () use (&$queue, &$active, $multi, $session): void {
        if ($queue === []) {
            return;
        }

        $filePath = array_shift($queue);
        $handle = createSecureJsonHandle($session, $filePath);
        $active[(int) $handle] = [
            "handle" => $handle,
            "filePath" => $filePath,
        ];

        curl_multi_add_handle($multi, $handle);
    };

    try {
        for ($index = 0; $index < min($concurrency, count($queue)); $index++) {
            $addNext();
        }

        do {
            do {
                $multiStatus = curl_multi_exec($multi, $running);
            } while ($multiStatus === CURLM_CALL_MULTI_PERFORM);

            if ($multiStatus !== CURLM_OK) {
                throw new RuntimeException("The upstream batch request failed.");
            }

            while (($info = curl_multi_info_read($multi)) !== false) {
                $handle = $info["handle"];
                $key = (int) $handle;

                if (!isset($active[$key])) {
                    continue;
                }

                $filePath = $active[$key]["filePath"];
                $response = curl_multi_getcontent($handle);
                $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

                if ($info["result"] !== CURLE_OK) {
                    $error = curl_error($handle);
                    curl_multi_remove_handle($multi, $handle);
                    curl_close($handle);
                    unset($active[$key]);
                    throw new RuntimeException("Unable to fetch {$filePath}.", 0, new RuntimeException($error));
                }

                if ($status < 200 || $status >= 300) {
                    curl_multi_remove_handle($multi, $handle);
                    curl_close($handle);
                    unset($active[$key]);
                    throw new RuntimeException("The upstream endpoint rejected {$filePath}.");
                }

                $results[$filePath] = decodeJsonResponse($response, $filePath);

                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);
                unset($active[$key]);
                $addNext();
            }

            if ($running > 0) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running > 0 || $active !== []);
    } finally {
        foreach ($active as $item) {
            curl_multi_remove_handle($multi, $item["handle"]);
            curl_close($item["handle"]);
        }

        curl_multi_close($multi);
    }

    return $results;
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

function buildDashboardPayload(array $sources, string $userAgent, bool $shouldVerifyTls, string $caBundle): array
{
    $cached = readCache("dashboard_payload_v2", 120);

    if ($cached !== null) {
        return $cached;
    }

    $prRows = withSecureSession($sources["pr"], $userAgent, $shouldVerifyTls, $caBundle, static function (array $session) use ($sources): array {
        return fetchSecureJsonFile($session, $sources["pr"]["dataFile"]);
    });

    $fptpRows = withSecureSession($sources["fptp"], $userAgent, $shouldVerifyTls, $caBundle, static function (array $session) use ($sources): array {
        return fetchSecureJsonFile($session, $sources["fptp"]["dataFile"]);
    });

    $payload = [
        "pr" => normalizePrRows($prRows, $sources["pr"]["seatCount"]),
        "fptp" => normalizeFptpRows($fptpRows),
        "meta" => [
            "prSeats" => $sources["pr"]["seatCount"],
            "fptpSeats" => $sources["fptp"]["seatCount"],
            "totalSeats" => $sources["pr"]["seatCount"] + $sources["fptp"]["seatCount"],
            "generatedAt" => gmdate(DATE_ATOM),
        ],
    ];

    writeCache("dashboard_payload_v2", $payload);

    return $payload;
}

function pickWinningCandidate(array $rows): ?array
{
    if ($rows === []) {
        return null;
    }

    usort($rows, static function (array $left, array $right): int {
        return ((int) ($right["TotalVoteReceived"] ?? 0)) <=> ((int) ($left["TotalVoteReceived"] ?? 0));
    });

    foreach ($rows as $row) {
        if (strcasecmp((string) ($row["Remarks"] ?? ""), "Elected") === 0) {
            return $row;
        }
    }

    return $rows[0];
}

function buildMapPayload(array $sources, string $userAgent, bool $shouldVerifyTls, string $caBundle): array
{
    $cached = readCache("fptp_map_payload_v1", 900);

    if ($cached !== null) {
        return $cached;
    }

    $payload = withSecureSession($sources["map"], $userAgent, $shouldVerifyTls, $caBundle, static function (array $session): array {
        $lookup = fetchSecureJsonFile($session, "JSONFiles/Election2082/HOR/Lookup/constituencies.json");
        $districtIds = [];
        $resultFiles = [];

        foreach ($lookup as $district) {
            $districtId = (int) ($district["distId"] ?? 0);
            $constituencyCount = (int) ($district["consts"] ?? 0);

            if ($districtId < 1 || $constituencyCount < 1) {
                continue;
            }

            $districtIds[] = $districtId;

            for ($constituencyId = 1; $constituencyId <= $constituencyCount; $constituencyId++) {
                $resultFiles[] = "JSONFiles/Election2082/HOR/FPTP/HOR-{$districtId}-{$constituencyId}.json";
            }
        }

        $districtIds = array_values(array_unique($districtIds));
        $geoFiles = array_map(static function (int $districtId): string {
            return "JSONFiles/JSONMap/geojson/Const/dist-{$districtId}.json";
        }, $districtIds);

        $geoJsonBatches = fetchSecureJsonBatch($session, $geoFiles, 10);
        $resultBatches = fetchSecureJsonBatch($session, $resultFiles, 16);
        $winnerByKey = [];

        foreach ($resultBatches as $filePath => $rows) {
            if (!preg_match('/HOR-(\d+)-(\d+)\.json$/', $filePath, $matches)) {
                continue;
            }

            $districtId = (int) $matches[1];
            $constituencyId = (int) $matches[2];
            $winner = pickWinningCandidate($rows);

            if ($winner === null) {
                continue;
            }

            $winnerByKey["{$districtId}-{$constituencyId}"] = [
                "winnerPartyName" => (string) ($winner["PoliticalPartyName"] ?? "Unknown Party"),
                "winnerCandidateName" => (string) ($winner["CandidateName"] ?? "Unknown Candidate"),
                "winnerVotes" => (int) ($winner["TotalVoteReceived"] ?? 0),
                "winnerStatus" => (string) ($winner["Remarks"] ?? "Leading"),
                "symbolId" => (int) ($winner["SymbolID"] ?? 0),
                "districtName" => (string) ($winner["DistrictName"] ?? ""),
                "stateName" => (string) ($winner["StateName"] ?? ""),
            ];
        }

        $features = [];

        foreach ($geoJsonBatches as $geoJson) {
            foreach (($geoJson["features"] ?? []) as $feature) {
                $properties = $feature["properties"] ?? [];
                $districtId = (int) ($properties["DCODE"] ?? 0);
                $constituencyId = (int) ($properties["F_CONST"] ?? 0);
                $winner = $winnerByKey["{$districtId}-{$constituencyId}"] ?? null;

                $feature["properties"]["districtId"] = $districtId;
                $feature["properties"]["constituencyId"] = $constituencyId;
                $feature["properties"]["winnerPartyName"] = $winner["winnerPartyName"] ?? null;
                $feature["properties"]["winnerCandidateName"] = $winner["winnerCandidateName"] ?? null;
                $feature["properties"]["winnerVotes"] = $winner["winnerVotes"] ?? 0;
                $feature["properties"]["winnerStatus"] = $winner["winnerStatus"] ?? null;
                $feature["properties"]["symbolId"] = $winner["symbolId"] ?? 0;
                $feature["properties"]["districtName"] = $winner["districtName"] ?? null;
                $feature["properties"]["stateName"] = $winner["stateName"] ?? null;

                $features[] = $feature;
            }
        }

        return [
            "geojson" => [
                "type" => "FeatureCollection",
                "features" => $features,
            ],
            "meta" => [
                "constituencyCount" => count($features),
                "generatedAt" => gmdate(DATE_ATOM),
            ],
        ];
    });

    writeCache("fptp_map_payload_v1", $payload);

    return $payload;
}

try {
    $view = isset($_GET["view"]) ? (string) $_GET["view"] : "dashboard";

    if ($view === "map") {
        respondWithJson(buildMapPayload($sources, $userAgent, $shouldVerifyTls, (string) $caBundle));
        exit;
    }

    respondWithJson(buildDashboardPayload($sources, $userAgent, $shouldVerifyTls, (string) $caBundle));
} catch (Throwable $exception) {
    $details = ["message" => $exception->getMessage()];
    $previous = $exception->getPrevious();

    if ($previous instanceof Throwable) {
        $details["previous"] = $previous->getMessage();
    }

    respondWithError(502, "Unable to load election data.", $details);
}