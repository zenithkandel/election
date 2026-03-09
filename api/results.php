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
        "X-Requested-With: XMLHttpRequest"
    ]
]);

$response = curl_exec($ch);
curl_close($ch);

echo $response;