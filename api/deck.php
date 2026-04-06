<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$action = $_GET['action'] ?? '';

try {
    match ($action) {
        'index' => handleIndex(),
        'deck'  => handleDeck(),
        default => jsonError('Unknown action', 400),
    };
} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 400);
} catch (RuntimeException $e) {
    jsonError($e->getMessage(), 404);
} catch (Throwable) {
    jsonError('Internal error', 500);
}

// ── Handlers ──────────────────────────────────────────────────────────────────

/**
 * Return the deck index with coverImageUrl replaced by proxy URLs.
 * imageUrl fields are never exposed to the client.
 */
function handleIndex(): void
{
    $index = loadIndex();

    $result = array_map(function (array $entry): array {
        return [
            'id'            => $entry['id']    ?? '',
            'title'         => $entry['title'] ?? '',
            'coverImageUrl' => 'api/image.php?deck=' . urlencode($entry['id'] ?? '') . '&type=cover',
            'file'          => $entry['file']  ?? '',
            'type'          => $entry['type']  ?? '',
        ];
    }, $index);

    jsonOk($result);
}

/**
 * Return a single deck's data with all imageUrl fields removed from cards.
 */
function handleDeck(): void
{
    $deckId = validateId($_GET['id'] ?? '');

    $index    = loadIndex();
    $entry    = findDeckEntry($index, $deckId);
    $deckJson = loadDeckJson($entry['file']);

    // Strip imageUrl from the deck-level object
    $deck = $deckJson['deck'] ?? [];
    unset($deck['coverImageUrl'], $deck['titleImageUrl']);

    // Strip imageUrl from every card
    $cards = array_map(function (array $card): array {
        unset($card['imageUrl']);
        return $card;
    }, $deckJson['cards'] ?? []);

    jsonOk([
        'deck'       => $deck,
        'categories' => $deckJson['categories'] ?? [],
        'cards'      => $cards,
    ]);
}
