<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\Key;
use Firebase\JWT\JWT;

function firebasePhoneAuthWebConfig(): ?array
{
    $config = [
        'apiKey' => (string)(getenv('FIREBASE_API_KEY') ?: ''),
        'authDomain' => (string)(getenv('FIREBASE_AUTH_DOMAIN') ?: ''),
        'projectId' => (string)(getenv('FIREBASE_PROJECT_ID') ?: ''),
        'appId' => (string)(getenv('FIREBASE_APP_ID') ?: ''),
    ];

    foreach ($config as $value) {
        if ($value === '') {
            return null;
        }
    }

    return $config;
}

function firebaseSigningCertificates(string $projectId): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Firebase signing keys could not be requested.');
    }

    $curl = curl_init('https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com');
    if ($curl === false) {
        throw new RuntimeException('Firebase signing keys could not be requested.');
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $response = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $failed = curl_errno($curl) !== 0;
    curl_close($curl);

    $certificates = is_string($response) && !$failed && $status === 200
        ? json_decode($response, true)
        : null;
    if (!is_array($certificates) || $certificates === []) {
        throw new RuntimeException('Firebase signing keys could not be loaded.');
    }

    return $certificates;
}

function decodeFirebasePhoneIdToken(string $idToken, string $projectId, array $certificates): ?array
{
    $keys = [];
    foreach ($certificates as $keyId => $certificate) {
        if (is_string($keyId) && is_string($certificate) && $certificate !== '') {
            $keys[$keyId] = new Key($certificate, 'RS256');
        }
    }
    if ($keys === []) {
        throw new RuntimeException('Firebase signing keys are invalid.');
    }

    try {
        $headers = null;
        $claims = JWT::decode($idToken, $keys, $headers);
    } catch (UnexpectedValueException | DomainException | InvalidArgumentException $exception) {
        return null;
    }

    $firebaseClaims = $claims->firebase ?? null;
    $phone = (string)($claims->phone_number ?? '');
    $authTime = (int)($claims->auth_time ?? 0);
    $now = time();
    if (($claims->aud ?? null) !== $projectId
        || ($claims->iss ?? null) !== 'https://securetoken.google.com/' . $projectId
        || !is_string($claims->sub ?? null)
        || $claims->sub === ''
        || strlen($claims->sub) > 128
        || !is_object($firebaseClaims)
        || ($firebaseClaims->sign_in_provider ?? null) !== 'phone'
        || preg_match('/^\+[1-9]\d{7,14}$/', $phone) !== 1
        || $authTime < $now - 900
        || $authTime > $now) {
        return null;
    }

    return ['uid' => $claims->sub, 'phone' => $phone];
}

function verifyFirebasePhoneIdToken(string $idToken): ?array
{
    $projectId = (string)(getenv('FIREBASE_PROJECT_ID') ?: '');
    if ($projectId === '') {
        throw new RuntimeException('Firebase project is not configured.');
    }
    if ($idToken === '' || strlen($idToken) > 8192 || count(explode('.', $idToken)) !== 3) {
        return null;
    }

    return decodeFirebasePhoneIdToken(
        $idToken,
        $projectId,
        firebaseSigningCertificates($projectId)
    );
}
?>
