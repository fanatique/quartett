<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

// ── Constants ─────────────────────────────────────────────────────────────────

const CARD_W  = 520;
const CARD_H  = 220;
const COVER_W = 400;
const COVER_H = 200;
const WEBP_QUALITY    = 85;
const MAX_DOWNLOAD_B  = 5 * 1024 * 1024; // 5 MB
const MAX_REDIRECTS   = 5;
const CURL_TIMEOUT_S  = 10;

// ── Entry point ───────────────────────────────────────────────────────────────

try {
    handleImageRequest();
} catch (InvalidArgumentException $e) {
    imageError($e->getMessage(), 400);
} catch (RuntimeException $e) {
    imageError($e->getMessage(), 502);
} catch (Throwable) {
    imageError('Internal error', 500);
}

// ── Request handler ───────────────────────────────────────────────────────────

function handleImageRequest(): void
{
    $deckId = validateId($_GET['deck'] ?? '');

    // Determine cache key and image-URL resolver
    if (isset($_GET['card'])) {
        $cardId   = validateId($_GET['card']);
        $cacheKey = $cardId;
        $isCard   = true;
    } elseif (($_GET['type'] ?? '') === 'cover') {
        $cacheKey = 'cover';
        $isCard   = false;
    } else {
        throw new InvalidArgumentException('Provide card= or type=cover');
    }

    // ── Build + validate cache path ───────────────────────────────────────────
    $cacheDir  = CACHE_DIR . '/' . $deckId;
    $cachePath = $cacheDir . '/' . $cacheKey . '.webp';

    // Prevent any path traversal before the directory even exists
    assertSafePath($cachePath, CACHE_DIR);

    // ── Serve from cache if available ─────────────────────────────────────────
    if (is_file($cachePath)) {
        serveWebP($cachePath);
    }

    // ── Resolve image URL from deck JSON ──────────────────────────────────────
    $index     = loadIndex();
    $entry     = findDeckEntry($index, $deckId);
    $deckJson  = loadDeckJson($entry['file']);

    if ($isCard) {
        $imageUrl = findCardImageUrl($deckJson, $cardId);
        $targetW  = CARD_W;
        $targetH  = CARD_H;
    } else {
        $imageUrl = $deckJson['deck']['coverImageUrl'] ?? null;
        if (!$imageUrl) {
            throw new RuntimeException('No coverImageUrl in deck');
        }
        $targetW = COVER_W;
        $targetH = COVER_H;
    }

    // ── Security: domain allowlist ────────────────────────────────────────────
    assertAllowedDomain($imageUrl);

    // ── Download ──────────────────────────────────────────────────────────────
    $rawBytes = fetchImage($imageUrl);

    // ── Verify it is actually an image ────────────────────────────────────────
    $sizeInfo = @getimagesizefromstring($rawBytes);
    if ($sizeInfo === false) {
        throw new RuntimeException('Downloaded content is not a valid image');
    }

    // ── GD: crop + resize → WebP ──────────────────────────────────────────────
    $src = @imagecreatefromstring($rawBytes);
    if ($src === false) {
        throw new RuntimeException('GD could not decode image');
    }

    $webpData = processImage($src, $targetW, $targetH);
    imagedestroy($src);

    // ── Atomically write cache ────────────────────────────────────────────────
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0750, true);
    }

    $tmp = tempnam($cacheDir, '.img_');
    if ($tmp === false) {
        throw new RuntimeException('Could not create temp file');
    }

    if (file_put_contents($tmp, $webpData) === false) {
        @unlink($tmp);
        throw new RuntimeException('Could not write temp file');
    }

    // Final realpath check before rename (paranoia: ensures still inside CACHE_DIR)
    assertSafePath($tmp, CACHE_DIR);

    if (!rename($tmp, $cachePath)) {
        @unlink($tmp);
        throw new RuntimeException('Could not move image to cache');
    }

    // ── Serve ─────────────────────────────────────────────────────────────────
    serveWebPData($webpData);
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function findCardImageUrl(array $deckJson, string $cardId): string
{
    foreach ($deckJson['cards'] ?? [] as $card) {
        if (($card['id'] ?? '') === $cardId) {
            $url = $card['imageUrl'] ?? null;
            if (!$url) {
                throw new RuntimeException('Card has no imageUrl');
            }
            return $url;
        }
    }
    throw new RuntimeException('Card not found in deck');
}

/**
 * Download a remote image via cURL.
 * Enforces timeout, redirect limit, and maximum download size.
 */
function fetchImage(string $url): string
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => MAX_REDIRECTS,
        CURLOPT_TIMEOUT        => CURL_TIMEOUT_S,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'QuartettImageProxy/1.0',
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        // Abort if downloaded more than MAX_DOWNLOAD_B
        CURLOPT_NOPROGRESS     => false,
        CURLOPT_PROGRESSFUNCTION => function ($ch, $dlTotal, $dlNow) {
            if ($dlNow > MAX_DOWNLOAD_B) {
                return 1; // non-zero aborts transfer
            }
            return 0;
        },
    ]);

    $data  = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $http  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        throw new RuntimeException('Download failed: ' . $error);
    }
    if ($http !== 200) {
        throw new RuntimeException('Remote returned HTTP ' . $http);
    }
    if (!is_string($data) || $data === '') {
        throw new RuntimeException('Empty response from remote');
    }
    if (strlen($data) > MAX_DOWNLOAD_B) {
        throw new RuntimeException('Downloaded image exceeds size limit');
    }

    return $data;
}

/**
 * Center-crop and resize a GD image resource to $w×$h, return WebP bytes.
 */
function processImage(\GdImage $src, int $w, int $h): string
{
    $srcW = imagesx($src);
    $srcH = imagesy($src);

    // Calculate crop dimensions (cover-fit: fill without distortion)
    $srcAspect = $srcW / $srcH;
    $dstAspect = $w / $h;

    if ($srcAspect > $dstAspect) {
        // Source is wider → crop sides
        $cropH = $srcH;
        $cropW = (int)round($srcH * $dstAspect);
        $cropX = (int)round(($srcW - $cropW) / 2);
        $cropY = 0;
    } else {
        // Source is taller → crop top/bottom
        $cropW = $srcW;
        $cropH = (int)round($srcW / $dstAspect);
        $cropX = 0;
        $cropY = (int)round(($srcH - $cropH) / 2);
    }

    $dst = imagecreatetruecolor($w, $h);
    if ($dst === false) {
        throw new RuntimeException('GD could not create output image');
    }

    imagecopyresampled($dst, $src, 0, 0, $cropX, $cropY, $w, $h, $cropW, $cropH);

    ob_start();
    imagewebp($dst, null, WEBP_QUALITY);
    $webpData = ob_get_clean();
    imagedestroy($dst);

    if ($webpData === false || $webpData === '') {
        throw new RuntimeException('GD WebP encoding failed');
    }

    return $webpData;
}

/**
 * Ensure $path resolves to a location inside $baseDir.
 * For paths that do not yet exist (e.g. not-yet-created subdirectories),
 * walks up the directory tree until an existing ancestor is found, then
 * verifies that ancestor is within $baseDir.
 */
function assertSafePath(string $path, string $baseDir): void
{
    $base = realpath($baseDir);
    if ($base === false) {
        throw new RuntimeException('Cache base directory not found');
    }

    // Walk up until we find an existing filesystem node
    $check = $path;
    while (true) {
        $resolved = realpath($check);
        if ($resolved !== false) {
            break;
        }
        $parent = dirname($check);
        if ($parent === $check) {
            // Reached filesystem root without finding any existing ancestor
            throw new RuntimeException('Cache directory structure is invalid');
        }
        $check = $parent;
    }

    $insideBase = str_starts_with($resolved, $base . DIRECTORY_SEPARATOR) || $resolved === $base;
    if (!$insideBase) {
        throw new RuntimeException('Path escapes cache directory');
    }
}

/**
 * Serve a WebP file from disk with caching headers.
 */
function serveWebP(string $path): never
{
    $data = @file_get_contents($path);
    if ($data === false) {
        throw new RuntimeException('Could not read cached file');
    }
    serveWebPData($data);
}

function serveWebPData(string $data): never
{
    header('Content-Type: image/webp');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=86400, immutable');
    header('Content-Length: ' . strlen($data));
    echo $data;
    exit;
}

function imageError(string $message, int $status): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $message]);
    exit;
}
