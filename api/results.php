<?php

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

$url = "https://result.election.gov.np/Handlers/SecureJson.ashx?file=JSONFiles/Election2082/Common/PRHoRPartyTop5.txt";

$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,

    CURLOPT_HTTPHEADER => [
        "Accept: application/json",
        "Accept-Language: en-US,en;q=0.9",
        "Connection: keep-alive",
        "Referer: https://result.election.gov.np/",
        "X-Requested-With: XMLHttpRequest"
    ],

    CURLOPT_USERAGENT => "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36"
]);

$response = curl_exec($ch);

if (curl_errno($ch)) {
    echo json_encode(["error" => curl_error($ch)]);
}

curl_close($ch);

echo $response;