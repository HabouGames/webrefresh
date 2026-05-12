<?php
// ════════════════════════════════════════════
//  NeuErLook – Content Save Endpoint
//  Wird vom Visual Editor auf der Website aufgerufen
//  Speichert Textänderungen in inhalte.json
// ════════════════════════════════════════════

// ── KONFIGURATION ──────────────────────────
define('ADMIN_EMAIL',    'deine@email.de');   // Muss mit admin.php übereinstimmen
define('INHALTE_DATEI',  'inhalte.json');
define('SESSION_DAUER',  3600);
// ───────────────────────────────────────────

session_start();
header('Content-Type: application/json; charset=utf-8');

// Nur POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'Method not allowed']));
}

// Auth prüfen: muss eingeloggt sein via admin.php
if (!isset($_SESSION['admin_eingeloggt']) || $_SESSION['admin_eingeloggt'] !== true) {
    http_response_code(401);
    exit(json_encode(['ok' => false, 'error' => 'Nicht eingeloggt. Bitte zuerst /admin.php öffnen.']));
}

// Session abgelaufen?
if (time() - ($_SESSION['login_zeit'] ?? 0) > SESSION_DAUER) {
    session_destroy();
    http_response_code(401);
    exit(json_encode(['ok' => false, 'error' => 'Session abgelaufen. Bitte neu einloggen.']));
}

// JSON Body lesen
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data || !is_array($data)) {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'Ungültige Daten']));
}

// Erlaubte Felder (Whitelist)
$erlaubt = [
    'eyebrow', 'hero-line1', 'hero-line2', 'hero-line3', 'hero-sub',
    'phone', 'cta-title', 'cta-sub', 'cta-phone',
    'leistungen-title', 'leistungen-sub',
    's1-title', 's1-text', 's2-title', 's2-text', 's3-title', 's3-text',
];

// Bestehende Inhalte laden
$inhalte = [];
if (file_exists(INHALTE_DATEI)) {
    $inhalte = json_decode(file_get_contents(INHALTE_DATEI), true) ?? [];
}

// Neue Werte mergen (nur erlaubte Felder)
$gespeichert = 0;
foreach ($data as $key => $val) {
    if (in_array($key, $erlaubt)) {
        $inhalte[$key] = htmlspecialchars(trim((string)$val), ENT_QUOTES, 'UTF-8');
        $gespeichert++;
    }
}

// Speichern
$result = file_put_contents(
    INHALTE_DATEI,
    json_encode($inhalte, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
);

if ($result === false) {
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => 'Schreiben fehlgeschlagen. Dateirechte prüfen.']));
}

exit(json_encode([
    'ok'          => true,
    'gespeichert' => $gespeichert,
    'felder'      => array_keys(array_intersect_key($data, array_flip($erlaubt)))
]));
