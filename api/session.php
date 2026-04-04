<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

const SESSIONS_DIR = __DIR__ . '/sessions';
const DECKS_DIR = __DIR__ . '/../decks';
const SESSION_MAX_AGE = 14 * 86400; // 2 weeks
const GC_PROBABILITY = 1; // 1 in 100

// --- Entry Point ---

$action = $_GET['action'] ?? '';

try {
    match ($action) {
        'create' => handleCreate(),
        'join'   => handleJoin(),
        'state'  => handleState(),
        'tap'    => handleTap(),
        'select' => handleSelect(),
        'next'   => handleNext(),
        default  => sendError('Unknown action', 400),
    };
} catch (\Throwable $e) {
    sendError($e->getMessage(), 500);
}

// --- Handlers ---

function handleCreate(): void {
    $input = getJsonInput();
    $deckId = $input['deckId'] ?? '';
    $playerToken = $input['playerToken'] ?? '';

    if (!$deckId || !$playerToken) {
        sendError('deckId and playerToken required', 400);
    }

    // Load and validate deck
    $deck = loadDeck($deckId);
    if (!$deck) {
        sendError('Deck not found', 404);
    }

    // Filter complete cards and shuffle
    $cards = array_values(array_filter($deck['cards'], fn($c) => $c['status'] === 'complete'));
    if (count($cards) < 2) {
        sendError('Not enough complete cards in deck', 400);
    }
    shuffle($cards);

    $half = intdiv(count($cards), 2);
    $playerACards = array_map(fn($c) => $c['id'], array_slice($cards, 0, $half));
    $playerBCards = array_map(fn($c) => $c['id'], array_slice($cards, $half, $half));

    $sessionId = bin2hex(random_bytes(4));
    $startingPlayer = random_int(0, 1) === 0 ? 'A' : 'B';

    $session = [
        'id' => $sessionId,
        'deckId' => $deckId,
        'createdAt' => time(),
        'updatedAt' => time(),
        'playerAToken' => $playerToken,
        'playerBToken' => null,
        'status' => 'waiting',
        'currentPlayer' => $startingPlayer,
        'phase' => 'tap-stack',
        'playerACards' => $playerACards,
        'playerBCards' => $playerBCards,
        'currentCardAId' => null,
        'currentCardBId' => null,
        'selectedCategory' => null,
        'roundWinner' => null,
        'gameWinner' => null,
        'version' => 1,
    ];

    writeSession($sessionId, $session);

    // Probabilistic cleanup
    if (random_int(1, 100) <= GC_PROBABILITY) {
        cleanupOldSessions();
    }

    sendJson([
        'sessionId' => $sessionId,
        'playerRole' => 'A',
        'deckId' => $deckId,
    ]);
}

function handleJoin(): void {
    $input = getJsonInput();
    $sessionId = $input['sessionId'] ?? '';
    $playerToken = $input['playerToken'] ?? '';

    if (!$sessionId || !$playerToken) {
        sendError('sessionId and playerToken required', 400);
    }

    $result = withSessionLock($sessionId, function (&$session) use ($playerToken) {
        // Already this player?
        if ($session['playerAToken'] === $playerToken) {
            return ['playerRole' => 'A', 'deckId' => $session['deckId'], 'status' => $session['status']];
        }
        if ($session['playerBToken'] === $playerToken) {
            return ['playerRole' => 'B', 'deckId' => $session['deckId'], 'status' => $session['status']];
        }

        // Join as player B
        if ($session['playerBToken'] !== null) {
            throw new \RuntimeException('Session is full');
        }

        $session['playerBToken'] = $playerToken;
        $session['status'] = 'active';
        $session['updatedAt'] = time();
        $session['version']++;

        return ['playerRole' => 'B', 'deckId' => $session['deckId'], 'status' => 'active'];
    });

    sendJson($result);
}

function handleState(): void {
    $sessionId = $_GET['sessionId'] ?? '';
    $playerToken = $_GET['playerToken'] ?? '';
    $lastVersion = (int)($_GET['version'] ?? 0);

    if (!$sessionId || !$playerToken) {
        sendError('sessionId and playerToken required', 400);
    }

    $session = readSession($sessionId);
    if (!$session) {
        sendError('Session not found', 404);
    }

    $role = identifyPlayer($session, $playerToken);
    if (!$role) {
        sendError('Not a player in this session', 403);
    }

    // Quick check: if version unchanged, return minimal response
    if ($lastVersion > 0 && $session['version'] === $lastVersion) {
        sendJson(['changed' => false, 'version' => $session['version']]);
        return;
    }

    sendJson(buildStateResponse($session, $role));
}

function handleTap(): void {
    $input = getJsonInput();
    $sessionId = $input['sessionId'] ?? '';
    $playerToken = $input['playerToken'] ?? '';

    if (!$sessionId || !$playerToken) {
        sendError('sessionId and playerToken required', 400);
    }

    $result = withSessionLock($sessionId, function (&$session) use ($playerToken) {
        $role = identifyPlayer($session, $playerToken);
        if (!$role) throw new \RuntimeException('Not a player in this session');
        if ($session['status'] !== 'active') throw new \RuntimeException('Game not active');
        if ($session['phase'] !== 'tap-stack') throw new \RuntimeException('Not in tap-stack phase');
        if ($session['currentPlayer'] !== $role) throw new \RuntimeException('Not your turn');

        // Set current cards (top of each stack)
        $session['currentCardAId'] = $session['playerACards'][0] ?? null;
        $session['currentCardBId'] = $session['playerBCards'][0] ?? null;
        $session['selectedCategory'] = null;
        $session['roundWinner'] = null;
        $session['phase'] = 'select-category';
        $session['updatedAt'] = time();
        $session['version']++;

        return buildStateResponse($session, $role);
    });

    sendJson($result);
}

function handleSelect(): void {
    $input = getJsonInput();
    $sessionId = $input['sessionId'] ?? '';
    $playerToken = $input['playerToken'] ?? '';
    $categoryKey = $input['categoryKey'] ?? '';

    if (!$sessionId || !$playerToken || !$categoryKey) {
        sendError('sessionId, playerToken, and categoryKey required', 400);
    }

    $result = withSessionLock($sessionId, function (&$session) use ($playerToken, $categoryKey) {
        $role = identifyPlayer($session, $playerToken);
        if (!$role) throw new \RuntimeException('Not a player in this session');
        if ($session['status'] !== 'active') throw new \RuntimeException('Game not active');
        if (!in_array($session['phase'], ['select-category', 'stich'])) {
            throw new \RuntimeException('Not in selection phase');
        }
        if ($session['currentPlayer'] !== $role) throw new \RuntimeException('Not your turn');

        // Load deck for category metadata and card stats
        $deck = loadDeck($session['deckId']);
        if (!$deck) throw new \RuntimeException('Deck not found');

        $category = null;
        foreach ($deck['categories'] as $cat) {
            if ($cat['key'] === $categoryKey) { $category = $cat; break; }
        }
        if (!$category) throw new \RuntimeException('Invalid category');

        // Find card data
        $cardA = findCard($deck, $session['currentCardAId']);
        $cardB = findCard($deck, $session['currentCardBId']);
        if (!$cardA || !$cardB) throw new \RuntimeException('Card not found');

        $valA = $cardA['stats'][$categoryKey] ?? null;
        $valB = $cardB['stats'][$categoryKey] ?? null;

        // Check for tie (Stich)
        if ($valA === $valB) {
            $session['selectedCategory'] = $categoryKey;
            $session['phase'] = 'stich';
            $session['updatedAt'] = time();
            $session['version']++;
            return buildStateResponse($session, $role);
        }

        // Determine winner
        if ($category['better'] === 'higher') {
            $winner = $valA > $valB ? 'A' : 'B';
        } else {
            $winner = $valA < $valB ? 'A' : 'B';
        }

        $session['selectedCategory'] = $categoryKey;
        $session['roundWinner'] = $winner;
        $session['phase'] = 'show-result';
        $session['updatedAt'] = time();
        $session['version']++;

        return buildStateResponse($session, $role);
    });

    sendJson($result);
}

function handleNext(): void {
    $input = getJsonInput();
    $sessionId = $input['sessionId'] ?? '';
    $playerToken = $input['playerToken'] ?? '';

    if (!$sessionId || !$playerToken) {
        sendError('sessionId and playerToken required', 400);
    }

    $result = withSessionLock($sessionId, function (&$session) use ($playerToken) {
        $role = identifyPlayer($session, $playerToken);
        if (!$role) throw new \RuntimeException('Not a player in this session');
        if ($session['status'] !== 'active') throw new \RuntimeException('Game not active');
        if ($session['phase'] !== 'show-result') throw new \RuntimeException('Not in show-result phase');
        if ($session['roundWinner'] !== $role) throw new \RuntimeException('Only the winner can advance');

        // Move cards: remove top card from each stack, give both to winner
        $cardA = array_shift($session['playerACards']);
        $cardB = array_shift($session['playerBCards']);

        if ($session['roundWinner'] === 'A') {
            $session['playerACards'][] = $cardA;
            $session['playerACards'][] = $cardB;
        } else {
            $session['playerBCards'][] = $cardA;
            $session['playerBCards'][] = $cardB;
        }

        // Check game over
        if (empty($session['playerACards'])) {
            $session['gameWinner'] = 'B';
            $session['status'] = 'finished';
            $session['phase'] = 'game-over';
        } elseif (empty($session['playerBCards'])) {
            $session['gameWinner'] = 'A';
            $session['status'] = 'finished';
            $session['phase'] = 'game-over';
        } else {
            // Next round: winner plays
            $session['currentPlayer'] = $session['roundWinner'];
            $session['phase'] = 'tap-stack';
            $session['currentCardAId'] = null;
            $session['currentCardBId'] = null;
            $session['selectedCategory'] = null;
            $session['roundWinner'] = null;
        }

        $session['updatedAt'] = time();
        $session['version']++;

        return buildStateResponse($session, $role);
    });

    sendJson($result);
}

// --- State Response Builder ---

function buildStateResponse(array $session, string $myRole): array {
    $deck = loadDeck($session['deckId']);
    $cardA = $session['currentCardAId'] ? findCard($deck, $session['currentCardAId']) : null;
    $cardB = $session['currentCardBId'] ? findCard($deck, $session['currentCardBId']) : null;

    $phase = $session['phase'];
    $currentPlayer = $session['currentPlayer'];

    // Determine card visibility
    // Active player's card: visible after tap (phase >= select-category)
    // Opponent's card: visible only in show-result or game-over
    $showCardA = false;
    $showCardB = false;
    $revealPhases = ['show-result', 'game-over'];

    if (in_array($phase, ['select-category', 'stich', 'show-result', 'game-over'])) {
        // The card of the player who tapped is visible
        if ($currentPlayer === 'A') $showCardA = true;
        if ($currentPlayer === 'B') $showCardB = true;
    }
    if (in_array($phase, $revealPhases)) {
        // Both cards visible during result
        $showCardA = true;
        $showCardB = true;
    }

    $response = [
        'changed' => true,
        'version' => $session['version'],
        'status' => $session['status'],
        'myRole' => $myRole,
        'currentPlayer' => $currentPlayer,
        'phase' => $phase,
        'playerACardCount' => count($session['playerACards']),
        'playerBCardCount' => count($session['playerBCards']),
        'selectedCategory' => $session['selectedCategory'],
        'roundWinner' => $session['roundWinner'],
        'gameWinner' => $session['gameWinner'],
        'cardA' => $showCardA && $cardA ? [
            'id' => $cardA['id'],
            'title' => $cardA['title'],
            'imageUrl' => $cardA['imageUrl'],
            'stats' => $cardA['stats'],
        ] : null,
        'cardB' => $showCardB && $cardB ? [
            'id' => $cardB['id'],
            'title' => $cardB['title'],
            'imageUrl' => $cardB['imageUrl'],
            'stats' => $cardB['stats'],
        ] : null,
        'cardARevealed' => $showCardA,
        'cardBRevealed' => $showCardB,
    ];

    return $response;
}

// --- Helper Functions ---

function getJsonInput(): array {
    $raw = file_get_contents('php://input');
    return $raw ? (json_decode($raw, true) ?? []) : [];
}

function sendJson(array $data): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function sendError(string $message, int $code): void {
    http_response_code($code);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function identifyPlayer(array $session, string $token): ?string {
    if ($session['playerAToken'] === $token) return 'A';
    if ($session['playerBToken'] === $token) return 'B';
    return null;
}

function loadDeck(string $deckId): ?array {
    // Validate deckId to prevent path traversal
    if (!preg_match('/^[a-z0-9\-]+$/', $deckId)) return null;

    $indexPath = DECKS_DIR . '/index.json';
    if (!is_file($indexPath)) return null;

    $index = json_decode(file_get_contents($indexPath), true);
    if (!$index) return null;

    $entry = null;
    foreach ($index as $item) {
        if ($item['id'] === $deckId) { $entry = $item; break; }
    }
    if (!$entry) return null;

    $deckPath = DECKS_DIR . '/' . $entry['file'];
    if (!is_file($deckPath)) return null;

    return json_decode(file_get_contents($deckPath), true);
}

function findCard(array $deck, string $cardId): ?array {
    foreach ($deck['cards'] as $card) {
        if ($card['id'] === $cardId) return $card;
    }
    return null;
}

// --- Session File I/O with Locking ---

function sessionPath(string $id): string {
    if (!preg_match('/^[a-f0-9]{8}$/', $id)) {
        throw new \RuntimeException('Invalid session ID');
    }
    return SESSIONS_DIR . '/' . $id . '.json';
}

function readSession(string $id): ?array {
    $path = sessionPath($id);
    if (!is_file($path)) return null;

    $fp = fopen($path, 'r');
    if (!$fp) return null;

    flock($fp, LOCK_SH);
    $data = json_decode(stream_get_contents($fp), true);
    flock($fp, LOCK_UN);
    fclose($fp);

    return $data;
}

function writeSession(string $id, array $data): void {
    $path = sessionPath($id);
    $fp = fopen($path, 'c');
    if (!$fp) throw new \RuntimeException('Cannot open session file');

    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

function withSessionLock(string $id, callable $fn): array {
    $path = sessionPath($id);
    if (!is_file($path)) {
        sendError('Session not found', 404);
    }

    $fp = fopen($path, 'c+');
    if (!$fp) throw new \RuntimeException('Cannot open session file');

    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $session = json_decode($raw, true);
    if (!$session) {
        flock($fp, LOCK_UN);
        fclose($fp);
        throw new \RuntimeException('Corrupt session file');
    }

    $result = $fn($session);

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($session, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return $result;
}

// --- Cleanup ---

function cleanupOldSessions(): void {
    $cutoff = time() - SESSION_MAX_AGE;
    foreach (glob(SESSIONS_DIR . '/*.json') as $file) {
        if (filemtime($file) < $cutoff) {
            @unlink($file);
        }
    }
}
