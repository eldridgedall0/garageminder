<?php
/**
 * VIN Recall Check using NHTSA API
 * Checks for open safety recalls by VIN
 *
 * NHTSA VIN recall endpoint: https://api.nhtsa.gov/recalls/recallsByVin?vin=VIN
 * Response shape: { "Count": N, "Message": "...", "results": [...] }
 */

header('Content-Type: application/json; charset=utf-8');

$vin = $_GET['vin'] ?? '';

// Validate VIN
if (empty($vin)) {
    echo json_encode(['success' => false, 'message' => 'VIN is required']);
    exit;
}

// Basic VIN validation (17 characters, alphanumeric, no I, O, Q)
$vin = strtoupper(trim($vin));
if (!preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $vin)) {
    echo json_encode(['success' => false, 'message' => 'Invalid VIN format. Must be 17 characters (letters A-H, J-N, P, R-Z and numbers 0-9).']);
    exit;
}

try {
    // =========================================================
    // Step 1: Decode VIN to get year/make/model for display
    // =========================================================
    $vehicleInfo = null;
    $decodeUrl = 'https://vpic.nhtsa.dot.gov/api/vehicles/decodevinvalues/' . urlencode($vin) . '?format=json';
    $decodeResponse = makeApiRequest($decodeUrl);

    if ($decodeResponse !== false) {
        $decodeData = json_decode($decodeResponse, true);
        if (isset($decodeData['Results'][0])) {
            $r = $decodeData['Results'][0];
            $vehicleInfo = [
                'year'  => $r['ModelYear'] ?? null,
                'make'  => $r['Make']      ?? null,
                'model' => $r['Model']     ?? null,
            ];
        }
    }

    // =========================================================
    // Step 2: VIN-specific recall lookup ONLY
    //
    // We intentionally do NOT fall back to make/model/year lookup.
    // That endpoint returns every recall ever issued for a model
    // regardless of whether it applies to this specific vehicle,
    // producing false positives (e.g. 12 results instead of 1).
    // =========================================================
    $httpStatus   = 0;
    $vinRecallUrl = 'https://api.nhtsa.gov/recalls/recallsByVin?vin=' . urlencode($vin);
    $rawResponse  = makeApiRequest($vinRecallUrl, $httpStatus);

    if ($rawResponse === false) {
        throw new Exception(
            'Could not reach the NHTSA recall database (HTTP ' . $httpStatus . '). ' .
            'Please try again later or check manually at nhtsa.gov/recalls.'
        );
    }

    $data = json_decode($rawResponse, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Received an invalid response from the NHTSA API. Please try again later.');
    }

    // The API returns { "Count": 0, "Message": "...", "results": [] } for no recalls.
    // It returns { "Count": N, "Message": "...", "results": [...] } when recalls exist.
    // Both are valid success responses — "no recalls" is not an error.
    if (!array_key_exists('Count', $data) && !array_key_exists('results', $data)) {
        throw new Exception(
            'Unexpected response from NHTSA API. Please try again later or check manually at nhtsa.gov/recalls.'
        );
    }

    $recalls     = isset($data['results']) && is_array($data['results']) ? $data['results'] : [];
    $recallCount = count($recalls);

    // =========================================================
    // Step 3: Normalise recall records
    // =========================================================
    $formattedRecalls = [];
    foreach ($recalls as $recall) {
        $formattedRecalls[] = [
            'id'           => $recall['NHTSACampaignNumber']
                           ?? $recall['nhtsaCampaignNumber']
                           ?? $recall['CampaignNumber']
                           ?? 'N/A',
            'component'    => $recall['Component']
                           ?? $recall['component']
                           ?? 'Unknown Component',
            'summary'      => $recall['Summary']
                           ?? $recall['summary']
                           ?? 'No summary available',
            'consequence'  => $recall['Consequence']
                           ?? $recall['consequence']
                           ?? '',
            'remedy'       => $recall['Remedy']
                           ?? $recall['remedy']
                           ?? '',
            'date'         => $recall['ReportReceivedDate']
                           ?? $recall['reportReceivedDate']
                           ?? '',
            'manufacturer' => $recall['Manufacturer']
                           ?? $recall['manufacturer']
                           ?? '',
            'url'          => 'https://www.nhtsa.gov/recalls?vymm=' . urlencode($vin),
        ];
    }

    // =========================================================
    // Build final response
    // =========================================================
    $result = [
        'success'    => true,
        'vin'        => $vin,
        'count'      => $recallCount,
        'hasRecalls' => $recallCount > 0,
        'recalls'    => $formattedRecalls,
        'checkedAt'  => date('Y-m-d H:i:s'),
        'nhtsaUrl'   => 'https://www.nhtsa.gov/recalls?vin=' . urlencode($vin),
    ];

    if ($vehicleInfo && ($vehicleInfo['year'] || $vehicleInfo['make'] || $vehicleInfo['model'])) {
        $result['vehicleInfo'] = $vehicleInfo;
    }

    echo json_encode($result);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}

/**
 * Make an API request with proper error handling.
 *
 * @param  string   $url        URL to request
 * @param  int|null &$httpStatus HTTP status code (passed by reference)
 * @return string|false Response body, or false on failure
 */
function makeApiRequest(string $url, ?int &$httpStatus = null) {
    $httpStatus = 0;

    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'User-Agent: GarageMaintenanceApp/1.0 (Vehicle Service Tracker)',
            ],
        ]);

        $response   = curl_exec($ch);
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError  = curl_error($ch);
        curl_close($ch);

        error_log("NHTSA API Request: $url — HTTP $httpStatus");

        if ($response === false) {
            error_log("CURL Error: $curlError");
            return false;
        }

        // 200 = success; 404 with a JSON body = valid "not found" from NHTSA
        if ($httpStatus === 200 || $httpStatus === 404) {
            return $response;
        }

        return false;
    }

    // Fallback to file_get_contents
    $context = stream_context_create([
        'http' => [
            'timeout'       => 20,
            'user_agent'    => 'GarageMaintenanceApp/1.0 (Vehicle Service Tracker)',
            'ignore_errors' => true,
            'header'        => 'Accept: application/json',
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);

    $response = @file_get_contents($url, false, $context);

    if ($response !== false && isset($http_response_header)) {
        if (preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0] ?? '', $m)) {
            $httpStatus = (int) $m[1];
        }
    }

    return $response !== false ? $response : false;
}
