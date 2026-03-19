<?php
/**
 * VIN Recall Check using NHTSA API
 * Checks for open safety recalls by VIN
 *
 * NHTSA API Documentation:
 *   VIN recall lookup: https://api.nhtsa.gov/recalls/recallsByVin?vin=VIN
 *   VIN decode:        https://vpic.nhtsa.dot.gov/api/vehicles/decodevinvalues/VIN?format=json
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
    // Step 2: Recall lookup — try methods in order of accuracy
    // =========================================================
    $recalls    = [];
    $apiWorked  = false;

    // ------------------------------------------------------------------
    // Method 1: recallsByVin  (VIN-specific — most accurate)
    // Correct endpoint: /recalls/recallsByVin?vin=VIN
    // ------------------------------------------------------------------
    $vinRecallUrl = 'https://api.nhtsa.gov/recalls/recallsByVin?vin=' . urlencode($vin);
    $response = makeApiRequest($vinRecallUrl);

    if ($response !== false) {
        $data = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            // Response shape: { "Count": N, "Message": "...", "results": [...] }
            if (isset($data['results']) && is_array($data['results'])) {
                $recalls   = $data['results'];
                $apiWorked = true;
                error_log("NHTSA recalls/recallsByVin returned " . count($recalls) . " results for $vin");
            } elseif (isset($data['Count']) && $data['Count'] === 0) {
                // Explicit zero — API worked, no recalls
                $recalls   = [];
                $apiWorked = true;
                error_log("NHTSA recalls/recallsByVin returned 0 results for $vin");
            }
        }
    }

    // ------------------------------------------------------------------
    // Method 2: recallsByVehicle with make/model/year (broader — may
    // include recalls not specific to this VIN's production window, but
    // better than nothing when Method 1 fails or returns nothing and
    // we suspect there should be recalls)
    // ------------------------------------------------------------------
    if (!$apiWorked && $vehicleInfo
        && !empty($vehicleInfo['make'])
        && !empty($vehicleInfo['model'])
        && !empty($vehicleInfo['year'])
    ) {
        $makeModelUrl = 'https://api.nhtsa.gov/recalls/recallsByVehicle?' . http_build_query([
            'make'       => $vehicleInfo['make'],
            'model'      => $vehicleInfo['model'],
            'modelYear'  => $vehicleInfo['year'],
        ]);

        $response = makeApiRequest($makeModelUrl);

        if ($response !== false) {
            $data = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($data['results']) && is_array($data['results'])) {
                $recalls   = $data['results'];
                $apiWorked = true;
                error_log("NHTSA recallsByVehicle (make/model/year) returned " . count($recalls) . " results for $vin");
            }
        }
    }

    if (!$apiWorked) {
        throw new Exception(
            'Unable to connect to NHTSA recall database. Please try again later or check the VIN manually at nhtsa.gov/recalls'
        );
    }

    $recallCount = count($recalls);

    // =========================================================
    // Step 3: Normalise recall records (API field names vary)
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
                           ?? $recall['Consequence']
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
            'url'          => 'https://www.nhtsa.gov/recalls?vin=' . urlencode($vin),
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
 * @param  string $url URL to request
 * @return string|false Response body or false on failure
 */
function makeApiRequest(string $url) {
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

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        error_log("NHTSA API Request: $url — HTTP $httpCode");

        if ($response === false) {
            error_log("CURL Error: $curlError");
            return false;
        }

        if ($httpCode === 200) {
            return $response;
        }

        // 400/404 can carry a parseable JSON body — attempt to use it
        if ($httpCode === 400 || $httpCode === 404) {
            $data = json_decode($response, true);
            if ($data !== null) {
                return $response;
            }
            return false;
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
    return $response !== false ? $response : false;
}
