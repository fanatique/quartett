<?php
declare(strict_types=1);

const DECKS_DIR = __DIR__ . '/../decks';
const CACHE_DIR = __DIR__ . '/../cache';
const INDEX_FILE = DECKS_DIR . '/index.json';

/**
 * Domains from which the image proxy is allowed to fetch.
 * Add entries here when new decks reference images from other hosts.
 */
const ALLOWED_IMAGE_DOMAINS = [
    'commons.wikimedia.org',
    'upload.wikimedia.org',
    'static.necy.eu',
];

// ── Input validation ──────────────────────────────────────────────────────────

/**
 * Validate a deck or card identifier.
 * Allowed: letters, digits, hyphens, underscores; 1–80 characters.
 * Throws InvalidArgumentException on any violation.
 */
function validateId(string $input): string
{
    if (!preg_match('/^[a-zA-Z0-9_-]{1,80}$/', $input)) {
        throw new InvalidArgumentException('Invalid identifier');
    }
    return $input;
}

// ── Domain allowlist ──────────────────────────────────────────────────────────

/**
 * Ensure a URL's hostname is in the allowed-domains list.
 * Throws RuntimeException when the domain is not allowed.
 */
function assertAllowedDomain(string $url): void
{
    $host = parse_url($url, PHP_URL_HOST);
    if ($host === false || $host === null || $host === '') {
        throw new RuntimeException('Could not parse image URL host');
    }
    $host = strtolower($host);
    foreach (ALLOWED_IMAGE_DOMAINS as $allowed) {
        if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
            return;
        }
    }
    throw new RuntimeException('Image domain not in allowlist: ' . $host);
}

// ── Deck index helpers ────────────────────────────────────────────────────────

/**
 * Load and return the parsed deck index.
 */
function loadIndex(): array
{
    $raw = @file_get_contents(INDEX_FILE);
    if ($raw === false) {
        throw new RuntimeException('Could not read deck index');
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid deck index JSON');
    }
    return $data;
}

/**
 * Find a deck entry by ID from the index array.
 * Throws RuntimeException when not found.
 */
function findDeckEntry(array $index, string $deckId): array
{
    foreach ($index as $entry) {
        if (($entry['id'] ?? '') === $deckId) {
            return $entry;
        }
    }
    throw new RuntimeException('Deck not found');
}

/**
 * Load a deck JSON by its filename (from the index entry, never from user input).
 */
function loadDeckJson(string $filename): array
{
    // Filename comes from server-controlled index.json – still validate format.
    if (!preg_match('/^[a-zA-Z0-9_-]+\.json$/', $filename)) {
        throw new RuntimeException('Unexpected deck filename format');
    }
    $path = DECKS_DIR . '/' . $filename;
    $raw  = @file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException('Could not read deck file');
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid deck JSON');
    }
    return $data;
}

// ── HTTP response helpers ─────────────────────────────────────────────────────

function jsonOk(mixed $data): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function jsonError(string $message, int $status = 400): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo json_encode(['error' => $message]);
    exit;
}
