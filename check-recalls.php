<?php
/**
 * VIN Recall Check using NHTSA API
 * Checks for open safety recalls by VIN
 * 
 * NHTSA API Documentation: https://vpic.nhtsa.dot.gov/api/
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
    // First, validate the VIN using NHTSA's decode endpoint
    // This helps ensure the VIN is valid before checking recalls
    $decodeUrl = 'https://vpic.nhtsa.dot.gov/api/vehicles/decodevinvalues/' . urlencode($vin) . '?format=json';
    
    $vinValid = true;
    $vehicleInfo = null;
    
    // Try to decode VIN first to validate it
    $decodeResponse = makeApiRequest($decodeUrl);
    if ($decodeResponse !== false) {
        $decodeData = json_decode($decodeResponse, true);
        if (isset($decodeData['Results'][0])) {
            $result = $decodeData['Results'][0];
            // Check if VIN decoding returned errors
            $errorCode = $result['ErrorCode'] ?? '';
            // Error codes: 0 = no error, other codes indicate issues
            // Multiple error codes can be comma-separated
            $errorCodes = array_map('trim', explode(',', $errorCode));
            
            // If all we have is "0" or empty, VIN is valid
            $hasOnlyValidCodes = empty(array_filter($errorCodes, function($code) {
                return $code !== '' && $code !== '0';
            }));
            
            if (!$hasOnlyValidCodes) {
                // VIN has issues but might still work for recalls
                // Store vehicle info if available
                $vehicleInfo = [
                    'year' => $result['ModelYear'] ?? null,
                    'make' => $result['Make'] ?? null,
                    'model' => $result['Model'] ?? null,
                ];
            } else {
                $vehicleInfo = [
                    'year' => $result['ModelYear'] ?? null,
                    'make' => $result['Make'] ?? null,
                    'model' => $result['Model'] ?? null,
                ];
            }
        }
    }
    
    // NHTSA Recalls API endpoint
    // Try the recalls API with the campaignNumber endpoint first
    $recallUrl = 'https://api.nhtsa.gov/recalls/recallsByVehicle?make=' . urlencode($vehicleInfo['make'] ?? '') . 
                 '&model=' . urlencode($vehicleInfo['model'] ?? '') . 
                 '&modelYear=' . urlencode($vehicleInfo['year'] ?? '');
    
    // If we have vehicle info, try by make/model/year first, then by VIN
    $recalls = [];
    $apiWorked = false;
    
    // Method 1: Try the direct VIN recall lookup
    $vinRecallUrl = 'https://api.nhtsa.gov/recalls/recallsByVehicle?vin=' . urlencode($vin);
    $response = makeApiRequest($vinRecallUrl);
    
    if ($response !== false) {
        $data = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($data['results'])) {
            $recalls = $data['results'];
            $apiWorked = true;
        }
    }
    
    // Method 2: If VIN lookup failed and we have vehicle info, try make/model/year
    if (!$apiWorked && $vehicleInfo && $vehicleInfo['make'] && $vehicleInfo['model'] && $vehicleInfo['year']) {
        $makeModelUrl = 'https://api.nhtsa.gov/recalls/recallsByVehicle?' . http_build_query([
            'make' => $vehicleInfo['make'],
            'model' => $vehicleInfo['model'],
            'modelYear' => $vehicleInfo['year']
        ]);
        
        $response = makeApiRequest($makeModelUrl);
        
        if ($response !== false) {
            $data = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($data['results'])) {
                $recalls = $data['results'];
                $apiWorked = true;
            }
        }
    }
    
    // Method 3: Try alternative endpoint format
    if (!$apiWorked) {
        $altUrl = 'https://webapi.nhtsa.gov/api/Recalls/vehicle/vin/' . urlencode($vin) . '?format=json';
        $response = makeApiRequest($altUrl);
        
        if ($response !== false) {
            $data = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                if (isset($data['Results'])) {
                    $recalls = $data['Results'];
                    $apiWorked = true;
                } elseif (isset($data['results'])) {
                    $recalls = $data['results'];
                    $apiWorked = true;
                }
            }
        }
    }
    
    if (!$apiWorked) {
        throw new Exception('Unable to connect to NHTSA recall database. Please try again later or check the VIN manually at nhtsa.gov/recalls');
    }
    
    $recalls = is_array($recalls) ? $recalls : [];
    $recallCount = count($recalls);
    
    // Format recalls for display
    $formattedRecalls = [];
    foreach ($recalls as $recall) {
        // Handle different API response formats
        $formattedRecalls[] = [
            'id' => $recall['NHTSACampaignNumber'] ?? $recall['CampaignNumber'] ?? $recall['nhtsaCampaignNumber'] ?? 'N/A',
            'component' => $recall['Component'] ?? $recall['component'] ?? 'Unknown Component',
            'summary' => $recall['Summary'] ?? $recall['summary'] ?? $recall['Conequence'] ?? 'No summary available',
            'consequence' => $recall['Consequence'] ?? $recall['consequence'] ?? '',
            'remedy' => $recall['Remedy'] ?? $recall['remedy'] ?? '',
            'date' => $recall['ReportReceivedDate'] ?? $recall['reportReceivedDate'] ?? '',
            'manufacturer' => $recall['Manufacturer'] ?? $recall['manufacturer'] ?? '',
            'url' => 'https://www.nhtsa.gov/recalls?vin=' . urlencode($vin)
        ];
    }
    
    // Build response
    $response = [
        'success' => true,
        'vin' => $vin,
        'count' => $recallCount,
        'hasRecalls' => $recallCount > 0,
        'recalls' => $formattedRecalls,
        'checkedAt' => date('Y-m-d H:i:s'),
        'nhtsaUrl' => 'https://www.nhtsa.gov/recalls?vin=' . urlencode($vin)
    ];
    
    // Add vehicle info if available
    if ($vehicleInfo && ($vehicleInfo['year'] || $vehicleInfo['make'] || $vehicleInfo['model'])) {
        $response['vehicleInfo'] = $vehicleInfo;
    }
    
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * Make an API request with proper error handling
 * 
 * @param string $url The URL to request
 * @return string|false Response body or false on failure
 */
function makeApiRequest($url) {
    // Try CURL first (more reliable)
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: GarageMaintenanceApp/1.0 (Vehicle Service Tracker)'
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        // Log for debugging (remove in production)
        error_log("NHTSA API Request: $url - HTTP $httpCode");
        
        if ($response === false) {
            error_log("CURL Error: $curlError");
            return false;
        }
        
        // Accept 200 and 404 (no recalls found returns 404 sometimes)
        if ($httpCode === 200) {
            return $response;
        }
        
        // 400 might mean no data found, not necessarily an error
        if ($httpCode === 400 || $httpCode === 404) {
            // Try to parse the response anyway
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
            'timeout' => 20,
            'user_agent' => 'GarageMaintenanceApp/1.0 (Vehicle Service Tracker)',
            'ignore_errors' => true,
            'header' => 'Accept: application/json'
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true
        ]
    ]);
    
    $response = @file_get_contents($url, false, $context);
    
    if ($response === false) {
        return false;
    }
    
    return $response;
}