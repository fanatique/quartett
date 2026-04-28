#!/usr/bin/env php
<?php
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "Run this script from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/../api/common.php';

const CARD_W         = 520;
const CARD_H         = 220;
const COVER_W        = 400;
const COVER_H        = 200;
const WEBP_QUALITY   = 85;
const MAX_DOWNLOAD_B = 5 * 1024 * 1024;
const MAX_REDIRECTS  = 5;
const CURL_TIMEOUT_S = 10;

// ── CLI args ──────────────────────────────────────────────────────────────────

$force      = in_array('--force', $argv, true);
$filterDeck = null;
$delayMs    = 500; // ms between downloads; override with --delay=<ms>
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--deck=')) {
        $filterDeck = substr($arg, 7);
    }
    if (str_starts_with($arg, '--delay=')) {
        $delayMs = max(0, (int) substr($arg, 8));
    }
}

// ── Main ──────────────────────────────────────────────────────────────────────

$index = loadIndex();

$totalDownloaded = 0;
$totalSkipped    = 0;
$totalErrors     = 0;

foreach ($index as $entry) {
    $deckId = $entry['id'] ?? '';

    if ($filterDeck !== null && $deckId !== $filterDeck) {
        continue;
    }

    $deckJson = loadDeckJson($entry['file']);
    $cacheDir = CACHE_DIR . '/' . $deckId;

    $title = $deckJson['deck']['title'] ?? $deckId;
    echo "\n── {$title} ──\n";

    // Build list of (cacheKey, imageUrl, w, h) tuples
    $items = [];

    $coverUrl = $deckJson['deck']['coverImageUrl'] ?? null;
    if ($coverUrl) {
        $items[] = ['key' => 'cover', 'url' => $coverUrl, 'w' => COVER_W, 'h' => COVER_H];
    }

    foreach ($deckJson['cards'] ?? [] as $card) {
        $items[] = [
            'key' => $card['id']       ?? '',
            'url' => $card['imageUrl'] ?? '',
            'w'   => CARD_W,
            'h'   => CARD_H,
        ];
    }

    foreach ($items as $item) {
        $label     = str_pad($item['key'], 8);
        $cachePath = $cacheDir . '/' . $item['key'] . '.webp';

        if (!$force && is_file($cachePath)) {
            echo "  {$label}  skip (cached)\n";
            $totalSkipped++;
            continue;
        }

        try {
            assertAllowedDomain($item['url']);
            $raw = fetchImage($item['url']);

            if (@getimagesizefromstring($raw) === false) {
                throw new RuntimeException('Not a valid image');
            }

            $src = @imagecreatefromstring($raw);
            if ($src === false) {
                throw new RuntimeException('GD could not decode image');
            }

            $webpData = processImage($src, $item['w'], $item['h']);
            unset($src);

            if (!is_dir($cacheDir)) {
                mkdir($cacheDir, 0750, true);
            }

            $tmp = tempnam($cacheDir, '.img_');
            if ($tmp === false) {
                throw new RuntimeException('Could not create temp file');
            }
            file_put_contents($tmp, $webpData);
            rename($tmp, $cachePath);

            $kb = number_format(strlen($raw) / 1024, 0);
            echo "  {$label}  ok  ({$kb} KB → " . $item['w'] . 'x' . $item['h'] . " WebP)\n";
            $totalDownloaded++;

        } catch (Throwable $e) {
            echo "  {$label}  ERROR: " . $e->getMessage() . "\n";
            $totalErrors++;
        }

        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }
    }
}

echo "\n";
echo "Downloaded: {$totalDownloaded}  |  Skipped: {$totalSkipped}  |  Errors: {$totalErrors}\n";
exit($totalErrors > 0 ? 1 : 0);

// ── Image helpers (mirrors api/image.php) ─────────────────────────────────────

function fetchImage(string $url): string
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL              => $url,
        CURLOPT_RETURNTRANSFER   => true,
        CURLOPT_FOLLOWLOCATION   => true,
        CURLOPT_MAXREDIRS        => MAX_REDIRECTS,
        CURLOPT_TIMEOUT          => CURL_TIMEOUT_S,
        CURLOPT_CONNECTTIMEOUT   => 5,
        CURLOPT_SSL_VERIFYPEER   => true,
        CURLOPT_SSL_VERIFYHOST   => 2,
        CURLOPT_USERAGENT        => 'QuartettImageProxy/1.0',
        CURLOPT_PROTOCOLS        => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        CURLOPT_NOPROGRESS       => false,
        CURLOPT_PROGRESSFUNCTION => function ($ch, $dlTotal, $dlNow) {
            return $dlNow > MAX_DOWNLOAD_B ? 1 : 0;
        },
    ]);

    $data  = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $http  = curl_getinfo($ch, CURLINFO_HTTP_CODE);

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

function processImage(\GdImage $src, int $w, int $h): string
{
    $srcW = imagesx($src);
    $srcH = imagesy($src);

    $srcAspect = $srcW / $srcH;
    $dstAspect = $w / $h;

    if ($srcAspect > $dstAspect) {
        $cropH = $srcH;
        $cropW = (int) round($srcH * $dstAspect);
        $cropX = (int) round(($srcW - $cropW) / 2);
        $cropY = 0;
    } else {
        $cropW = $srcW;
        $cropH = (int) round($srcW / $dstAspect);
        $cropX = 0;
        $cropY = (int) round(($srcH - $cropH) / 2);
    }

    $dst = imagecreatetruecolor($w, $h);
    if ($dst === false) {
        throw new RuntimeException('GD could not create output image');
    }

    imagecopyresampled($dst, $src, 0, 0, $cropX, $cropY, $w, $h, $cropW, $cropH);

    ob_start();
    imagewebp($dst, null, WEBP_QUALITY);
    $webpData = ob_get_clean();
    unset($dst);

    if ($webpData === false || $webpData === '') {
        throw new RuntimeException('GD WebP encoding failed');
    }

    return $webpData;
}
