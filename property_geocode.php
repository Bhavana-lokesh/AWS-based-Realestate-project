<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function propertyGeocodeRespond(int $status, array $response): never
{
    http_response_code($status);
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function propertyGeocodeReadCache(mysqli $connection, string $addressHash): ?array
{
    $statement = $connection->prepare(
        'SELECT latitude, longitude, display_name, has_result, location_level, zoom
         FROM property_map_geocode_cache
         WHERE address_hash = ? AND expires_at > UTC_TIMESTAMP()
         LIMIT 1'
    );
    $statement->bind_param('s', $addressHash);
    $statement->execute();
    $cached = $statement->get_result()->fetch_assoc();
    $statement->close();

    if (!$cached) {
        return null;
    }

    if ((int)$cached['has_result'] !== 1) {
        return ['found' => false];
    }

    return [
        'found' => true,
        'latitude' => (float)$cached['latitude'],
        'longitude' => (float)$cached['longitude'],
        'display_name' => (string)$cached['display_name'],
        'location_level' => (string)$cached['location_level'],
        'zoom' => (int)$cached['zoom'],
    ];
}

function propertyGeocodeWriteCache(
    mysqli $connection,
    string $addressHash,
    ?float $latitude,
    ?float $longitude,
    ?string $displayName,
    bool $found,
    string $locationLevel,
    int $zoom
): void {
    $hasResult = $found ? 1 : 0;
    $statement = $connection->prepare(
        'INSERT INTO property_map_geocode_cache
            (address_hash, latitude, longitude, display_name, has_result, location_level, zoom, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, IF(? = 1,
             UTC_TIMESTAMP() + INTERVAL 180 DAY,
             UTC_TIMESTAMP() + INTERVAL 1 DAY))
         ON DUPLICATE KEY UPDATE
            latitude = VALUES(latitude),
            longitude = VALUES(longitude),
            display_name = VALUES(display_name),
            has_result = VALUES(has_result),
            location_level = VALUES(location_level),
            zoom = VALUES(zoom),
            expires_at = VALUES(expires_at)'
    );
    $statement->bind_param(
        'sddsisii',
        $addressHash,
        $latitude,
        $longitude,
        $displayName,
        $hasResult,
        $locationLevel,
        $zoom,
        $hasResult
    );
    $statement->execute();
    $statement->close();
}

function propertyGeocodeWaitForRateLimit(mysqli $connection): void
{
    $stateStatement = $connection->prepare(
        'SELECT CASE
            WHEN last_request_at IS NULL THEN 0
            ELSE GREATEST(0, 1000000 - TIMESTAMPDIFF(MICROSECOND, last_request_at, UTC_TIMESTAMP(6)))
         END AS wait_microseconds
         FROM property_map_geocode_state WHERE state_id = 1'
    );
    $stateStatement->execute();
    $state = $stateStatement->get_result()->fetch_assoc();
    $stateStatement->close();
    if (!$state) {
        throw new mysqli_sql_exception('Map geocoding state is not initialized.');
    }

    $waitMicroseconds = min(1000000, max(0, (int)$state['wait_microseconds']));
    if ($waitMicroseconds > 0) {
        usleep($waitMicroseconds);
    }

    $updateState = $connection->prepare(
        'UPDATE property_map_geocode_state
         SET last_request_at = UTC_TIMESTAMP(6) WHERE state_id = 1'
    );
    $updateState->execute();
    $updateState->close();
}

function propertyGeocodeQuery(string $baseUrl, string $address): ?array
{
    $geocoderUrl = $baseUrl . '/search?' . http_build_query([
        'format' => 'jsonv2',
        'addressdetails' => 1,
        'limit' => 1,
        'q' => $address,
    ], '', '&', PHP_QUERY_RFC3986);
    $curl = curl_init($geocoderUrl);
    if ($curl === false) {
        error_log('Property map geocoder could not initialize cURL.');
        return null;
    }

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_MAXFILESIZE => 1048576,
        CURLOPT_USERAGENT => 'EstateScope property map/1.0',
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $response = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($response === false || $status !== 200) {
        error_log('Property map geocoder request failed (HTTP ' . $status . '): ' . $curlError);
        return null;
    }

    $results = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($results)) {
        error_log('Property map geocoder returned an invalid response.');
        return null;
    }

    return $results;
}

function propertyGeocodeZoom(array $result, bool $locality): int
{
    $addressType = strtolower((string)($result['addresstype'] ?? ''));
    return match ($addressType) {
        'house', 'building' => 17,
        'road', 'street', 'residential', 'pedestrian', 'service' => 16,
        'neighbourhood', 'suburb' => 13,
        'city', 'town', 'village' => 12,
        default => $locality ? 12 : 15,
    };
}

function propertyGeocodeCachedResponse(array $cached): array
{
    return [
        'success' => true,
        'latitude' => $cached['latitude'],
        'longitude' => $cached['longitude'],
        'display_name' => $cached['display_name'],
        'location_level' => $cached['location_level'],
        'zoom' => $cached['zoom'],
    ];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    propertyGeocodeRespond(405, ['success' => false, 'message' => 'Only GET requests are supported.']);
}

$propertyId = filter_var(
    $_GET['pid'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
if ($propertyId === false || $propertyId === null) {
    propertyGeocodeRespond(400, ['success' => false, 'message' => 'A valid property ID is required.']);
}

try {
    $propertyStatement = $con->prepare(
        'SELECT property_id, seller_id, review_status, status, street, location, state, zip
         FROM property_listings WHERE property_id = ? LIMIT 1'
    );
    $propertyStatement->bind_param('i', $propertyId);
    $propertyStatement->execute();
    $property = $propertyStatement->get_result()->fetch_assoc();
    $propertyStatement->close();
} catch (mysqli_sql_exception $exception) {
    error_log('Property map lookup failed: ' . $exception->getMessage());
    propertyGeocodeRespond(503, ['success' => false, 'message' => 'The property location is temporarily unavailable.']);
}

if (!$property) {
    propertyGeocodeRespond(404, ['success' => false, 'message' => 'Property not found.']);
}

$userId = currentUserId();
$role = currentUserRole();
$sellerId = (int)$property['seller_id'];
$canViewUnapproved = ($sellerId === $userId && $role === 'agent') || $role === 'super_admin';
if (($property['review_status'] !== 'approved' && !$canViewUnapproved)
    || ($property['status'] === 'hold' && !$canViewUnapproved)) {
    propertyGeocodeRespond(404, ['success' => false, 'message' => 'Property not found.']);
}

$addressParts = [];
foreach (['street', 'location', 'state', 'zip'] as $addressField) {
    $addressValue = trim((string)($property[$addressField] ?? ''));
    if ($addressValue !== '') {
        $addressParts[$addressField] = $addressValue;
    }
}
$propertyAddress = implode(', ', array_values($addressParts));
$hasMapLocation = count($addressParts) >= 2
    && (isset($addressParts['location']) || (isset($addressParts['street']) && isset($addressParts['zip'])));
if (!$hasMapLocation) {
    propertyGeocodeRespond(422, ['success' => false, 'message' => 'There is not enough address information to locate this property.']);
}

$addressHash = hash('sha256', "locality-fallback-v1\n" . strtolower($propertyAddress));
$localityParts = array_intersect_key($addressParts, array_flip(['location', 'state', 'zip']));
$localityAddress = implode(', ', array_values($localityParts));
$responseStatus = 200;
$responseData = null;
$lockAcquired = false;

try {
    $cached = propertyGeocodeReadCache($con, $addressHash);
    if ($cached !== null) {
        $responseStatus = $cached['found'] ? 200 : 404;
        $responseData = $cached['found']
            ? propertyGeocodeCachedResponse($cached)
            : ['success' => false, 'message' => 'No map location could be found for this listing address.'];
    } else {
        // Serialize uncached lookups so all app instances share Nominatim's rate limit.
        $lockName = 'estatescope_nominatim_geocode';
        $lockStatement = $con->prepare('SELECT GET_LOCK(?, 5) AS acquired');
        $lockStatement->bind_param('s', $lockName);
        $lockStatement->execute();
        $lockAcquired = (int)($lockStatement->get_result()->fetch_assoc()['acquired'] ?? 0) === 1;
        $lockStatement->close();

        if (!$lockAcquired) {
            $responseStatus = 503;
            $responseData = ['success' => false, 'message' => 'Map lookup is busy. Please try again shortly.'];
        } else {
            $cached = propertyGeocodeReadCache($con, $addressHash);
            if ($cached !== null) {
                $responseStatus = $cached['found'] ? 200 : 404;
                $responseData = $cached['found']
                    ? propertyGeocodeCachedResponse($cached)
                    : ['success' => false, 'message' => 'No map location could be found for this listing address.'];
            } else {
                $geocoderBaseUrl = rtrim(
                    (string)(getenv('NOMINATIM_BASE_URL') ?: 'https://nominatim.openstreetmap.org'),
                    '/'
                );
                $parsedGeocoderUrl = parse_url($geocoderBaseUrl);
                if (!$parsedGeocoderUrl
                    || ($parsedGeocoderUrl['scheme'] ?? '') !== 'https'
                    || empty($parsedGeocoderUrl['host'])) {
                    $responseStatus = 503;
                    $responseData = ['success' => false, 'message' => 'The map geocoder is not configured correctly.'];
                } else {
                    propertyGeocodeWaitForRateLimit($con);
                    $geocoderResults = propertyGeocodeQuery($geocoderBaseUrl, $propertyAddress);
                    if ($geocoderResults === null) {
                        $responseStatus = 503;
                        $responseData = ['success' => false, 'message' => 'The map service could not be reached. Please try again later.'];
                    } else {
                        $locationLevel = isset($addressParts['street']) ? 'address' : 'locality';
                        $streetTypes = [
                            'house', 'building', 'road', 'street', 'residential',
                            'pedestrian', 'service', 'living_street',
                        ];
                        $firstResult = $geocoderResults[0] ?? null;
                        if ($firstResult && isset($addressParts['street'])
                            && !in_array(strtolower((string)($firstResult['addresstype'] ?? '')), $streetTypes, true)) {
                            $geocoderResults = [];
                        }

                        if (!$geocoderResults && $localityAddress !== '' && $localityAddress !== $propertyAddress) {
                            propertyGeocodeWaitForRateLimit($con);
                            $geocoderResults = propertyGeocodeQuery($geocoderBaseUrl, $localityAddress);
                            $locationLevel = 'locality';
                        }

                        if ($geocoderResults === null) {
                            $responseStatus = 503;
                            $responseData = ['success' => false, 'message' => 'The map service could not be reached. Please try again later.'];
                        } elseif (!$geocoderResults) {
                            propertyGeocodeWriteCache($con, $addressHash, null, null, null, false, 'locality', 12);
                            $responseStatus = 404;
                            $responseData = ['success' => false, 'message' => 'No map location could be found for this listing address or locality.'];
                        } else {
                            $firstResult = $geocoderResults[0];
                            $latitudeValue = $firstResult['lat'] ?? null;
                            $longitudeValue = $firstResult['lon'] ?? null;
                            $latitude = is_numeric($latitudeValue) ? (float)$latitudeValue : null;
                            $longitude = is_numeric($longitudeValue) ? (float)$longitudeValue : null;
                            if ($latitude === null || $longitude === null
                                || $latitude < -90 || $latitude > 90
                                || $longitude < -180 || $longitude > 180) {
                                error_log('Property map geocoder returned invalid coordinates.');
                                $responseStatus = 503;
                                $responseData = ['success' => false, 'message' => 'The map service returned an invalid location.'];
                            } else {
                                $displayName = trim((string)($firstResult['display_name'] ?? ''));
                                if ($displayName === '') {
                                    $displayName = $propertyAddress;
                                }
                                $zoom = propertyGeocodeZoom($firstResult, $locationLevel === 'locality');
                                propertyGeocodeWriteCache(
                                    $con,
                                    $addressHash,
                                    $latitude,
                                    $longitude,
                                    $displayName,
                                    true,
                                    $locationLevel,
                                    $zoom
                                );
                                $responseData = [
                                    'success' => true,
                                    'latitude' => $latitude,
                                    'longitude' => $longitude,
                                    'display_name' => $displayName,
                                    'location_level' => $locationLevel,
                                    'zoom' => $zoom,
                                ];
                            }
                        }
                    }
                }
            }
        }
    }
} catch (mysqli_sql_exception $exception) {
    error_log('Property map geocoding database error: ' . $exception->getMessage());
    $responseStatus = 503;
    $responseData = ['success' => false, 'message' => 'The property map is temporarily unavailable.'];
} finally {
    if ($lockAcquired) {
        try {
            $releaseStatement = $con->prepare('SELECT RELEASE_LOCK(?)');
            $releaseStatement->bind_param('s', $lockName);
            $releaseStatement->execute();
            $releaseStatement->close();
        } catch (mysqli_sql_exception $exception) {
            error_log('Could not release the property map geocoding lock: ' . $exception->getMessage());
        }
    }
}

propertyGeocodeRespond($responseStatus, $responseData ?? [
    'success' => false,
    'message' => 'The map location could not be loaded.',
]);
