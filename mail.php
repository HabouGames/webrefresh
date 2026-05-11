<?php
// ════════════════════════════════════════════
//  WebRefresh – Kontaktformular Handler
//  Diese Datei liegt auf dem Server des Kunden
//  und verarbeitet alle Formular-Anfragen.
// ════════════════════════════════════════════

// ── KONFIGURATION (anpassen) ──────────────────
define('EMPFAENGER_EMAIL', 'deine@email.de');        // Wohin sollen Anfragen gehen?
define('BETREFF_PREFIX',   '[WebRefresh] Neue Anfrage');
define('ERLAUBTE_ORIGIN',  '');                       // Leer = gleiche Domain, sonst z.B. 'https://deine-domain.de'
// ─────────────────────────────────────────────

// Nur POST erlauben
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'Method not allowed']));
}

// JSON-Antworten
header('Content-Type: application/json; charset=utf-8');

// CORS (falls nötig)
if (ERLAUBTE_ORIGIN) {
    header('Access-Control-Allow-Origin: ' . ERLAUBTE_ORIGIN);
}

// ── HONEYPOT-SPAM-SCHUTZ ─────────────────────
if (!empty($_POST['website_url'])) {
    // Bot hat das versteckte Feld ausgefüllt → ignorieren
    exit(json_encode(['ok' => true])); // Fake-Erfolg damit Bot nicht weitermacht
}

// ── RATE LIMITING (einfach via Session) ──────
session_start();
$now = time();
if (!isset($_SESSION['last_submit'])) {
    $_SESSION['last_submit'] = 0;
    $_SESSION['submit_count'] = 0;
}
// Max 3 Anfragen pro 10 Minuten
if ($now - $_SESSION['last_submit'] < 600) {
    if ($_SESSION['submit_count'] >= 3) {
        http_response_code(429);
        exit(json_encode(['ok' => false, 'error' => 'Zu viele Anfragen. Bitte warte 10 Minuten.']));
    }
} else {
    $_SESSION['submit_count'] = 0;
}
$_SESSION['last_submit'] = $now;
$_SESSION['submit_count']++;

// ── EINGABEN VALIDIEREN & SÄUBERN ────────────
function clean($str) {
    return htmlspecialchars(trim(strip_tags($str)), ENT_QUOTES, 'UTF-8');
}

$name       = clean($_POST['name']       ?? '');
$kontakt    = clean($_POST['kontakt']    ?? '');
$website    = clean($_POST['website_alt'] ?? '');
$preismodell = clean($_POST['preismodell'] ?? '');
$nachricht  = clean($_POST['nachricht']  ?? '');

// Pflichtfelder prüfen
if (empty($name) || empty($kontakt)) {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'Bitte Name und Kontakt angeben.']));
}

// E-Mail oder Telefon validieren
$hat_email = filter_var($kontakt, FILTER_VALIDATE_EMAIL);
$hat_tel   = preg_match('/^[\d\s\+\-\/\(\)]{7,}$/', $kontakt);
if (!$hat_email && !$hat_tel) {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'Bitte eine gültige E-Mail oder Telefonnummer angeben.']));
}

// ── E-MAIL ZUSAMMENBAUEN ─────────────────────
$betreff = BETREFF_PREFIX . ' von ' . $name;

$body = "Neue Vorschau-Anfrage über die WebRefresh-Website\n";
$body .= str_repeat('─', 50) . "\n\n";
$body .= "Name:         $name\n";
$body .= "Kontakt:      $kontakt\n";
if ($website) $body .= "Alte Website: $website\n";
if ($preismodell) $body .= "Preismodell:  $preismodell\n";
if ($nachricht)   $body .= "\nNachricht:\n$nachricht\n";
$body .= "\n" . str_repeat('─', 50) . "\n";
$body .= "Eingegangen:  " . date('d.m.Y H:i') . " Uhr\n";
$body .= "IP:           " . $_SERVER['REMOTE_ADDR'] . "\n";

// Headers
$headers  = "From: noreply@webrefresh.de\r\n";
$headers .= "Reply-To: $kontakt\r\n";
$headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

// ── SENDEN ───────────────────────────────────
$gesendet = mail(EMPFAENGER_EMAIL, $betreff, $body, $headers);

if ($gesendet) {
    exit(json_encode(['ok' => true, 'message' => 'Danke! Wir melden uns in 48 Stunden.']));
} else {
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => 'Senden fehlgeschlagen. Bitte ruf uns direkt an.']));
}
