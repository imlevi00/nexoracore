<?php
/**
 * S3-compatible API (SigV4) بۆ DigitalOcean Spaces — بێ composer.
 * تەنها PUT و DELETE پشتگیری دەکرێت.
 *
 * SigV4 و canonical path — بەکاردێت بۆ PutObject/DeleteObject لە هەموو بارکردنە یەکگرتووەکاندا.
 */

require_once __DIR__ . '/spaces.php';
require_once __DIR__ . '/local_storage.php';

/**
 * کۆدکردنی key بۆ URL و canonical path (slashes دەپارێنرێن)
 */
function spaces_encoded_object_path(string $objectKey): string {
    $objectKey = ltrim(str_replace('\\', '/', $objectKey), '/');
    return str_replace(['%2F', '%2B'], ['/', '+'], rawurlencode($objectKey));
}

/**
 * @throws RuntimeException
 */
function spaces_sig_v4_authorization(
    string $method,
    string $virtualHost,
    string $canonicalUri,
    string $queryString,
    array $headersLowerNameToValue,
    string $payloadHash,
    string $accessKey,
    string $secretKey,
    string $region,
    string $service
): string {
    $amzDate = $headersLowerNameToValue['x-amz-date'];
    $dateStamp = substr($amzDate, 0, 8);

    ksort($headersLowerNameToValue);
    $canonicalHeaders = '';
    $signedHeaderNames = [];
    foreach ($headersLowerNameToValue as $name => $value) {
        $canonicalHeaders .= $name . ':' . trim(preg_replace('/\s+/', ' ', $value)) . "\n";
        $signedHeaderNames[] = $name;
    }
    $signedHeaders = implode(';', $signedHeaderNames);

    $canonicalRequest = strtoupper($method) . "\n"
        . $canonicalUri . "\n"
        . $queryString . "\n"
        . $canonicalHeaders . "\n"
        . $signedHeaders . "\n"
        . $payloadHash;

    $algorithm = 'AWS4-HMAC-SHA256';
    $credentialScope = $dateStamp . '/' . $region . '/' . $service . '/aws4_request';
    $stringToSign = $algorithm . "\n"
        . $amzDate . "\n"
        . $credentialScope . "\n"
        . hash('sha256', $canonicalRequest);

    $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $secretKey, true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', $service, $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);

    return $algorithm
        . ' Credential=' . $accessKey . '/' . $credentialScope
        . ', SignedHeaders=' . $signedHeaders
        . ', Signature=' . $signature;
}

/**
 * ڕێڕەوی canonical URI — / + encoded path (وەک register.php)
 */
function spaces_canonical_uri_from_key(string $objectKey): string {
    return '/' . spaces_encoded_object_path($objectKey);
}

/**
 * URL ی تەواو بۆ virtual-hosted style
 *
 * @return array{0:string,1:string,2:string} url, virtualHost, canonicalUri
 */
function spaces_virtual_host_request_url(string $objectKey): array {
    $endpointHost = parse_url(SPACES_ENDPOINT, PHP_URL_HOST);
    $scheme = parse_url(SPACES_ENDPOINT, PHP_URL_SCHEME) ?: 'https';
    if (!$endpointHost) {
        throw new RuntimeException('SPACES_ENDPOINT نادروستە');
    }
    $virtualHost = SPACES_BUCKET . '.' . $endpointHost;
    $encodedPath = spaces_encoded_object_path($objectKey);
    $canonicalUri = '/' . $encodedPath;
    $url = $scheme . '://' . $virtualHost . '/' . $encodedPath;
    return [$url, $virtualHost, $canonicalUri];
}

/**
 * @throws RuntimeException
 */
function spaces_http_send(string $method, string $url, string $body, array $headers): void {
    $method = strtoupper($method);
    $headerLines = [];
    foreach ($headers as $k => $v) {
        $headerLines[] = $k . ': ' . $v;
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = $errno ? curl_error($ch) : '';
        curl_close($ch);
        if ($errno) {
            throw new RuntimeException('curl: ' . $curlErr);
        }
        if ($status < 200 || $status >= 300) {
            $bodyOut = is_string($resp) ? $resp : '';
            if (strlen($bodyOut) > 800) {
                $bodyOut = substr($bodyOut, -800);
            }
            throw new RuntimeException('Spaces HTTP ' . $status . ($bodyOut !== '' ? ' — ' . $bodyOut : ''));
        }
        return;
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headerLines),
            'content' => $body !== '' ? $body : null,
            'ignore_errors' => true,
        ],
    ]);
    $resp = @file_get_contents($url, false, $ctx);
    if (isset($http_response_header[0]) && preg_match('#HTTP/\S+\s+(\d+)#', $http_response_header[0], $m)) {
        $status = (int) $m[1];
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Spaces HTTP ' . $status . ($resp ? ' — ' . substr((string) $resp, 0, 500) : ''));
        }
        return;
    }
    throw new RuntimeException('Spaces request failed');
}

/**
 * PutObject — وەک register.php: x-amz-acl: public-read لە واژۆدا
 *
 * @throws RuntimeException
 */
function spaces_put_object(string $objectKey, string $body, string $contentType = 'application/octet-stream'): void {
    // ژینگەی لۆکاڵ یان نەبوونی ڕێکخستنی Spaces → خەزنی ناوخۆیی لەبری Spaces
    if (kasher_storage_is_local() || SPACES_KEY === '' || SPACES_SECRET === '' || SPACES_BUCKET === '' || SPACES_ENDPOINT === '') {
        kasher_local_put_object($objectKey, $body);
        return;
    }
    [$url, $virtualHost, $canonicalUri] = spaces_virtual_host_request_url($objectKey);
    $payloadHash = hash('sha256', $body);
    $amzDate = gmdate('Ymd\THis\Z');

    $headers = [
        'host' => $virtualHost,
        'content-type' => $contentType,
        'x-amz-acl' => 'public-read',
        'x-amz-content-sha256' => $payloadHash,
        'x-amz-date' => $amzDate,
    ];
    $auth = spaces_sig_v4_authorization(
        'PUT',
        $virtualHost,
        $canonicalUri,
        '',
        $headers,
        $payloadHash,
        SPACES_KEY,
        SPACES_SECRET,
        SPACES_REGION,
        's3'
    );
    $sendHeaders = [
        'Host' => $virtualHost,
        'Content-Type' => $contentType,
        'x-amz-acl' => 'public-read',
        'x-amz-content-sha256' => $payloadHash,
        'x-amz-date' => $amzDate,
        'Authorization' => $auth,
    ];
    spaces_http_send('PUT', $url, $body, $sendHeaders);
}

/**
 * @throws RuntimeException
 */
function spaces_delete_object(string $objectKey): void {
    // ژینگەی لۆکاڵ یان نەبوونی ڕێکخستنی Spaces → سڕینەوەی فایلی ناوخۆیی
    if (kasher_storage_is_local() || SPACES_KEY === '' || SPACES_SECRET === '' || SPACES_BUCKET === '' || SPACES_ENDPOINT === '') {
        kasher_local_delete_object($objectKey);
        return;
    }
    [$url, $virtualHost, $canonicalUri] = spaces_virtual_host_request_url($objectKey);
    $payloadHash = hash('sha256', '');
    $amzDate = gmdate('Ymd\THis\Z');

    $headers = [
        'host' => $virtualHost,
        'x-amz-content-sha256' => $payloadHash,
        'x-amz-date' => $amzDate,
    ];
    $auth = spaces_sig_v4_authorization(
        'DELETE',
        $virtualHost,
        $canonicalUri,
        '',
        $headers,
        $payloadHash,
        SPACES_KEY,
        SPACES_SECRET,
        SPACES_REGION,
        's3'
    );
    $sendHeaders = [
        'Host' => $virtualHost,
        'x-amz-content-sha256' => $payloadHash,
        'x-amz-date' => $amzDate,
        'Authorization' => $auth,
    ];
    spaces_http_send('DELETE', $url, '', $sendHeaders);
}

/**
 * URL ی گشتی بۆ object key (هەمان شێوازی product_image_url — بەشی path بە rawurlencode)
 */
function spaces_public_url_for_object_key(string $objectKey): ?string {
    $objectKey = ltrim(str_replace('\\', '/', $objectKey), '/');
    if ($objectKey === '') {
        return null;
    }
    // ئەگەر لەسەر سێرڤەر بووین و ڕێکخستنی Spaces بەردەست بوو
    if (!kasher_storage_is_local() && SPACES_KEY !== '' && SPACES_SECRET !== '' && SPACES_BUCKET !== '') {
        $parts = explode('/', $objectKey);
        $enc = array_map('rawurlencode', $parts);
        $path = implode('/', $enc);
        if (SPACES_PUBLIC_BASE_URL !== '') {
            return SPACES_PUBLIC_BASE_URL . '/' . $path;
        }
        if (SPACES_REGION !== '') {
            return 'https://' . SPACES_BUCKET . '.' . SPACES_REGION . '.cdn.digitaloceanspaces.com/' . $path;
        }
    }
    // ژینگەی لۆکاڵ یان fallback بۆ سێرڤەری بێ Spaces → URL ـی ناوخۆیی (SITE_URL/assets/uploads/…)
    return kasher_local_public_url_for_object_key($objectKey);
}

/**
 * سڕینەوەی object لە URL ی CDN/Spaces (بێ هەڵەدان ئەگەر ڕێکخستن یان URL نادروست بێت)
 */
function spaces_delete_object_from_public_url(?string $url): void {
    $url = trim((string) $url);
    if ($url === '' || stripos($url, 'digitaloceanspaces.com') === false) {
        return;
    }
    if (SPACES_KEY === '' || SPACES_SECRET === '' || SPACES_BUCKET === '' || SPACES_ENDPOINT === '') {
        return;
    }
    $parsed = parse_url($url);
    $path = isset($parsed['path']) ? $parsed['path'] : '';
    $objectKey = $path !== '' ? rawurldecode(ltrim($path, '/')) : '';
    if ($objectKey === '') {
        return;
    }
    try {
        spaces_delete_object($objectKey);
    } catch (Throwable $e) {
        if (function_exists('writeLog')) {
            writeLog('spaces_delete_object_from_public_url: ' . $e->getMessage());
        } else {
            error_log('spaces_delete_object_from_public_url: ' . $e->getMessage());
        }
    }
}
