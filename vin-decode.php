<?php
/**
 * VIN Decoder API Proxy - Server-side NHTSA API Integration
 * Decodes VIN using the free NHTSA vPIC API
 * 
 * Usage: GET /vin-decode.php?vin=1HGCM82633A004352
 * Returns JSON with year, make, model, engine, bodyClass
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=86400'); // Cache for 24 hours
require __DIR__ . '/config.php';

// Set security headers
gm_set_security_headers();

// Require authentication
$userId = 'default';
if (defined('ENABLE_MULTI_USER') && ENABLE_MULTI_USER) {
    $userId = gm_get_current_user_id();
    
    if (!$userId) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'authentication_required',
            'message' => 'Please log in to continue'
        ]);
        exit;
    }
}

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get and validate VIN
$vin = trim($_GET['vin'] ?? '');

if (empty($vin)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'VIN parameter is required']);
    exit;
}

// Basic VIN validation (should be 17 characters, alphanumeric, no I, O, Q)
$vin = strtoupper($vin);
if (!preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $vin)) {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'message' => 'Invalid VIN format. VIN must be 17 characters (letters and numbers, excluding I, O, Q)'
    ]);
    exit;
}

try {
    // Call NHTSA vPIC API
    $apiUrl = 'https://vpic.nhtsa.dot.gov/api/vehicles/DecodeVinValues/' . urlencode($vin) . '?format=json';
    
    // Use cURL for better error handling
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'GarageMaintenance/1.0 (Vehicle Lookup)',
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        throw new Exception('Failed to connect to NHTSA API: ' . $curlError);
    }
    
    if ($httpCode !== 200) {
        throw new Exception('NHTSA API returned status ' . $httpCode);
    }
    
    $data = json_decode($response, true);
    
    if (!$data || !isset($data['Results']) || !is_array($data['Results']) || empty($data['Results'])) {
        throw new Exception('Invalid response from NHTSA API');
    }
    
    $result = $data['Results'][0];
    
    // Check for errors in the response
    $errorCode = $result['ErrorCode'] ?? '';
    $errorText = $result['ErrorText'] ?? '';
    
    // Error codes: 0 = success, other codes indicate issues
    // Multiple error codes can be returned separated by commas
    $errorCodes = array_map('trim', explode(',', $errorCode));
    $hasErrors = false;
    $errorMessages = [];
    
    foreach ($errorCodes as $code) {
        if ($code !== '0' && $code !== '') {
            $hasErrors = true;
        }
    }
    
    // Extract relevant fields
    $year = !empty($result['ModelYear']) ? (int)$result['ModelYear'] : null;
    $make = !empty($result['Make']) ? trim($result['Make']) : null;
    $model = !empty($result['Model']) ? trim($result['Model']) : null;
    $bodyClass = !empty($result['BodyClass']) ? trim($result['BodyClass']) : null;
    
    // Build engine string from multiple fields
    $engineParts = [];
    
    // Displacement
    if (!empty($result['DisplacementL'])) {
        $engineParts[] = $result['DisplacementL'] . 'L';
    }
    
    // Configuration (V6, I4, etc.)
    if (!empty($result['EngineCylinders']) && !empty($result['EngineConfiguration'])) {
        $config = $result['EngineConfiguration'];
        $cylinders = $result['EngineCylinders'];
        
        // Shorten configuration names
        $configShort = $config;
        if (stripos($config, 'V-Shaped') !== false || stripos($config, 'V-') !== false) {
            $configShort = 'V' . $cylinders;
        } elseif (stripos($config, 'In-Line') !== false || stripos($config, 'Inline') !== false || stripos($config, 'I-') !== false) {
            $configShort = 'I' . $cylinders;
        } elseif (stripos($config, 'Flat') !== false || stripos($config, 'Horizontally') !== false) {
            $configShort = 'H' . $cylinders;
        } elseif (stripos($config, 'Rotary') !== false) {
            $configShort = 'Rotary';
        } else {
            $configShort = $cylinders . '-cyl';
        }
        
        $engineParts[] = $configShort;
    } elseif (!empty($result['EngineCylinders'])) {
        $engineParts[] = $result['EngineCylinders'] . '-cyl';
    }
    
    // Fuel type
    if (!empty($result['FuelTypePrimary'])) {
        $fuel = $result['FuelTypePrimary'];
        // Shorten common fuel types
        if (stripos($fuel, 'Gasoline') !== false) {
            // Don't add - it's the default
        } elseif (stripos($fuel, 'Diesel') !== false) {
            $engineParts[] = 'Diesel';
        } elseif (stripos($fuel, 'Electric') !== false) {
            $engineParts[] = 'Electric';
        } elseif (stripos($fuel, 'Hybrid') !== false || stripos($fuel, 'Plug-in') !== false) {
            $engineParts[] = 'Hybrid';
        } elseif (stripos($fuel, 'Flex') !== false) {
            $engineParts[] = 'Flex Fuel';
        } else {
            $engineParts[] = $fuel;
        }
    }
    
    // Turbo/Supercharged
    if (!empty($result['Turbo']) && strtolower($result['Turbo']) === 'yes') {
        $engineParts[] = 'Turbo';
    }
    
    // Horsepower (if available)
    if (!empty($result['EngineHP'])) {
        $engineParts[] = $result['EngineHP'] . 'hp';
    }
    
    $engine = !empty($engineParts) ? implode(' ', $engineParts) : null;
    
    // Check if we got meaningful data
    if (!$year && !$make && !$model) {
        echo json_encode([
            'success' => false,
            'message' => 'Could not decode VIN. ' . ($errorText ?: 'The VIN may be invalid or not in the NHTSA database.'),
            'errorCode' => $errorCode,
            'errorText' => $errorText
        ]);
        exit;
    }
    
    // Return successful response
    $output = [
        'success' => true,
        'data' => [
            'vin' => $vin,
            'year' => $year,
            'make' => $make,
            'model' => $model,
            'engine' => $engine,
            'bodyClass' => $bodyClass,
            // Include additional useful fields
            'driveType' => !empty($result['DriveType']) ? trim($result['DriveType']) : null,
            'vehicleType' => !empty($result['VehicleType']) ? trim($result['VehicleType']) : null,
            'plantCountry' => !empty($result['PlantCountry']) ? trim($result['PlantCountry']) : null,
            'manufacturer' => !empty($result['Manufacturer']) ? trim($result['Manufacturer']) : null,
            'series' => !empty($result['Series']) ? trim($result['Series']) : null,
            'trim' => !empty($result['Trim']) ? trim($result['Trim']) : null,
        ]
    ];
    
    // Add warning if there were non-fatal errors
    if ($hasErrors && $errorText) {
        $output['warning'] = $errorText;
    }
    
    echo json_encode($output, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'VIN lookup failed: ' . $e->getMessage()
    ]);
}