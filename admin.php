<?php
// ════════════════════════════════════════════
//  WebRefresh – Admin Panel
//  Aufruf: domain.de/admin.php
//  Login: E-Mail + 2FA-Code (6 Stellen, per Mail)
// ════════════════════════════════════════════

// ── KONFIGURATION (anpassen) ──────────────────
define('ADMIN_EMAIL',    'hallo@neuerlook.com');   // Deine Login-E-Mail
define('SESSION_DAUER',  3600);               // Eingeloggt bleiben für 1 Stunde (Sekunden)
define('CODE_GUELTIG',   600);                // 2FA-Code gültig für 10 Minuten
define('SITE_NAME',      'WebRefresh');
define('INHALTE_DATEI',  'inhalte.json');     // Hier werden Textänderungen gespeichert
// ─────────────────────────────────────────────

session_start();
$fehler  = '';
$erfolg  = '';
$schritt = 'email'; // 'email' → 'code' → 'eingeloggt'

// ── LOGOUT ───────────────────────────────────
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

// ── STATUS PRÜFEN ────────────────────────────
if (isset($_SESSION['admin_eingeloggt']) && $_SESSION['admin_eingeloggt'] === true) {
    // Session abgelaufen?
    if (time() - $_SESSION['login_zeit'] > SESSION_DAUER) {
        session_destroy();
        header('Location: admin.php?timeout=1');
        exit;
    }
    $schritt = 'eingeloggt';
} elseif (isset($_SESSION['email_bestaetigt']) && $_SESSION['email_bestaetigt'] === true) {
    $schritt = 'code';
}

// ── POST-VERARBEITUNG ────────────────────────

// Schritt 1: E-Mail prüfen & Code senden
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aktion']) && $_POST['aktion'] === 'email_senden') {
    $eingegebene_email = trim($_POST['email'] ?? '');

    if (strtolower($eingegebene_email) !== strtolower(ADMIN_EMAIL)) {
        $fehler = 'Diese E-Mail-Adresse ist nicht berechtigt.';
    } else {
        // 6-stelligen Code generieren
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $_SESSION['auth_code']          = password_hash($code, PASSWORD_DEFAULT);
        $_SESSION['code_erstellt']      = time();
        $_SESSION['email_bestaetigt']   = true;

        // Code per Mail senden
        $betreff = '[' . SITE_NAME . '] Ihr Login-Code: ' . $code;
        $body    = "Hallo,\n\n"
                 . "Ihr Einmal-Code für den Admin-Bereich:\n\n"
                 . "  $code\n\n"
                 . "Dieser Code ist " . (CODE_GUELTIG / 60) . " Minuten gültig.\n\n"
                 . "Falls Sie diesen Code nicht angefordert haben, ignorieren Sie diese Mail.\n\n"
                 . "– " . SITE_NAME;
        $headers = "From: noreply@webrefresh.de\r\nContent-Type: text/plain; charset=UTF-8\r\n";

        if (mail(ADMIN_EMAIL, $betreff, $body, $headers)) {
            $schritt = 'code';
            $erfolg  = 'Code gesendet! Bitte prüfe dein E-Mail-Postfach.';
        } else {
            $fehler  = 'E-Mail konnte nicht gesendet werden. PHP mail() prüfen.';
            $_SESSION['email_bestaetigt'] = false;
        }
    }
}

// Schritt 2: Code prüfen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aktion']) && $_POST['aktion'] === 'code_pruefen') {
    $eingegebener_code = trim($_POST['code'] ?? '');

    if (!isset($_SESSION['auth_code']) || !isset($_SESSION['code_erstellt'])) {
        $fehler = 'Session abgelaufen. Bitte neu starten.';
        $schritt = 'email';
    } elseif (time() - $_SESSION['code_erstellt'] > CODE_GUELTIG) {
        $fehler = 'Code abgelaufen. Bitte neu anfordern.';
        unset($_SESSION['auth_code'], $_SESSION['code_erstellt'], $_SESSION['email_bestaetigt']);
        $schritt = 'email';
    } elseif (!password_verify($eingegebener_code, $_SESSION['auth_code'])) {
        // Brute-Force-Schutz
        $_SESSION['code_versuche'] = ($_SESSION['code_versuche'] ?? 0) + 1;
        if ($_SESSION['code_versuche'] >= 5) {
            session_destroy();
            $fehler  = 'Zu viele Fehlversuche. Session beendet.';
            $schritt = 'email';
        } else {
            $fehler = 'Falscher Code. Noch ' . (5 - $_SESSION['code_versuche']) . ' Versuche.';
        }
    } else {
        // ✓ Eingeloggt
        $_SESSION['admin_eingeloggt'] = true;
        $_SESSION['login_zeit']       = time();
        unset($_SESSION['auth_code'], $_SESSION['code_erstellt'], $_SESSION['code_versuche']);
        $schritt = 'eingeloggt';
    }
}

// Schritt 3: Inhalte speichern
if ($schritt === 'eingeloggt' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aktion']) && $_POST['aktion'] === 'speichern') {
    $felder = ['eyebrow','hero-line1','hero-line2','hero-line3','hero-sub','phone','cta-title','cta-sub','cta-phone'];
    $inhalte = [];
    foreach ($felder as $feld) {
        if (isset($_POST[$feld])) {
            $inhalte[$feld] = htmlspecialchars(trim($_POST[$feld]), ENT_QUOTES, 'UTF-8');
        }
    }
    file_put_contents(INHALTE_DATEI, json_encode($inhalte, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $erfolg = 'Alle Änderungen gespeichert! Die Website wurde aktualisiert.';
}

// Schritt 4: Reset
if ($schritt === 'eingeloggt' && isset($_GET['reset'])) {
    if (file_exists(INHALTE_DATEI)) unlink(INHALTE_DATEI);
    $erfolg = 'Alle Inhalte auf Original zurückgesetzt.';
}

// Aktuelle Inhalte laden
$inhalte = [];
if (file_exists(INHALTE_DATEI)) {
    $inhalte = json_decode(file_get_contents(INHALTE_DATEI), true) ?? [];
}

function val($key, $default = '') {
    global $inhalte;
    return htmlspecialchars($inhalte[$key] ?? $default, ENT_QUOTES, 'UTF-8');
}

// ── SESSION-DAUER anzeigen ────────────────────
$minuten_uebrig = 0;
if ($schritt === 'eingeloggt') {
    $minuten_uebrig = round((SESSION_DAUER - (time() - $_SESSION['login_zeit'])) / 60);
}
?>

<!DOCTYPE html>

<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin – <?= SITE_NAME ?></title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    background: #f0f4f8; min-height: 100vh; color: #1a2d3d;
}

/* ── TOP BAR ── */
.topbar {
background: #003366; color: #fff;
padding: 0 32px; height: 58px;
display: flex; align-items: center; justify-content: space-between;
box-shadow: 0 2px 12px rgba(0,0,0,0.2);
}
.topbar-logo { font-size: 17px; font-weight: 700; letter-spacing: -.3px; }
.topbar-logo span { color: #c8a84b; }
.topbar-right { display: flex; align-items: center; gap: 16px; font-size: 13px; }
.topbar-timer { color: rgba(255,255,255,0.55); }
.logout-btn {
background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2);
color: #fff; padding: 7px 16px; border-radius: 5px; font-size: 13px;
text-decoration: none; transition: background .2s; cursor: pointer;
font-family: inherit;
}
.logout-btn:hover { background: rgba(255,255,255,0.2); }

/* ── LOGIN CARD ── */
.login-wrap {
min-height: calc(100vh - 58px);
display: flex; align-items: center; justify-content: center; padding: 32px;
}
.login-card {
background: #fff; border-radius: 14px; padding: 44px 40px;
width: 100%; max-width: 400px;
box-shadow: 0 8px 40px rgba(0,0,0,0.1);
}
.login-icon { font-size: 36px; margin-bottom: 12px; }
.login-title { font-size: 22px; font-weight: 700; color: #003366; margin-bottom: 6px; }
.login-sub { font-size: 14px; color: #6b7280; margin-bottom: 28px; line-height: 1.5; }

.step-indicator {
display: flex; gap: 8px; margin-bottom: 28px;
}
.step-dot {
height: 4px; flex: 1; border-radius: 2px; background: #e0e7ef;
transition: background .3s;
}
.step-dot.active { background: #003366; }
.step-dot.done   { background: #c8a84b; }

label {
display: block; font-size: 12px; font-weight: 700;
color: #6b7280; letter-spacing: 1px; text-transform: uppercase;
margin-bottom: 7px;
}
input[type=“email”], input[type=“text”], input[type=“password”],
input[type=“number”], textarea, select {
width: 100%; border: 1.5px solid #d1dae6; border-radius: 7px;
padding: 12px 14px; font-size: 15px; font-family: inherit;
outline: none; transition: border-color .2s; background: #fff;
color: #1a2d3d;
}
input:focus, textarea:focus, select:focus { border-color: #003366; }
.code-input {
font-size: 28px !important; font-weight: 700 !important;
letter-spacing: 8px !important; text-align: center !important;
}

.btn {
width: 100%; background: #003366; color: #fff; border: none;
border-radius: 7px; padding: 14px; font-size: 15px; font-weight: 700;
cursor: pointer; font-family: inherit; transition: background .2s;
margin-top: 16px;
}
.btn:hover { background: #004a99; }
.btn-gold { background: #c8a84b; }
.btn-gold:hover { background: #b8983b; }
.btn-sm {
width: auto; padding: 9px 20px; font-size: 13px; margin-top: 0;
}

.alert {
padding: 12px 16px; border-radius: 7px; font-size: 13px;
margin-bottom: 18px; line-height: 1.5;
}
.alert-error   { background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; }
.alert-success { background: #f0fdf4; border: 1px solid #86efac; color: #166534; }
.alert-info    { background: #eff6ff; border: 1px solid #93c5fd; color: #1e40af; }

.back-link {
display: block; text-align: center; margin-top: 16px;
font-size: 13px; color: #6b7280; text-decoration: none;
cursor: pointer;
}
.back-link:hover { color: #003366; }

/* ── ADMIN DASHBOARD ── */
.dashboard { max-width: 860px; margin: 0 auto; padding: 32px 24px 80px; }
.dash-header { margin-bottom: 28px; }
.dash-title  { font-size: 24px; font-weight: 700; color: #003366; margin-bottom: 4px; }
.dash-sub    { font-size: 14px; color: #6b7280; }

.section-card {
background: #fff; border-radius: 12px; padding: 28px 32px;
margin-bottom: 20px; box-shadow: 0 2px 12px rgba(0,0,0,0.06);
border: 1px solid #e8eef5;
}
.section-title {
font-size: 13px; font-weight: 700; color: #c8a84b;
letter-spacing: 1.5px; text-transform: uppercase;
margin-bottom: 20px; display: flex; align-items: center; gap: 8px;
}
.section-title::before { content: ‘’; width: 16px; height: 2px; background: #c8a84b; }

.fields-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
.field-group { }
.field-group.full { grid-column: span 2; }
.field-hint { font-size: 11px; color: #9ca3af; margin-top: 5px; }

.preview-badge {
display: inline-flex; align-items: center; gap: 6px;
background: #eff6ff; border: 1px solid #93c5fd; color: #1e40af;
font-size: 11px; font-weight: 600; padding: 4px 10px; border-radius: 12px;
margin-bottom: 12px;
}

.save-bar {
position: fixed; bottom: 0; left: 0; right: 0;
background: #fff; border-top: 1px solid #e0e7ef;
padding: 14px 32px; display: flex; align-items: center;
justify-content: space-between; gap: 16px;
box-shadow: 0 -4px 20px rgba(0,0,0,0.08); z-index: 100;
}
.save-bar-info { font-size: 13px; color: #6b7280; }

.danger-zone {
border: 1.5px solid #fca5a5 !important;
background: #fff5f5 !important;
}
.danger-zone .section-title { color: #dc2626; }
.danger-zone .section-title::before { background: #dc2626; }

@media (max-width: 600px) {
.fields-grid { grid-template-columns: 1fr; }
.field-group.full { grid-column: span 1; }
.login-card { padding: 32px 24px; }
.dashboard { padding: 20px 16px 80px; }
.save-bar { padding: 12px 16px; flex-wrap: wrap; }
}
</style>

</head>
<body>

<!-- TOP BAR -->

<div class="topbar">
    <div class="topbar-logo">Web<span>Refresh</span> · Admin</div>
    <?php if ($schritt === 'eingeloggt'): ?>
    <div class="topbar-right">
        <span class="topbar-timer">⏱ Noch <?= $minuten_uebrig ?> Min. eingeloggt</span>
        <a href="admin.php?logout=1" class="logout-btn">Abmelden</a>
    </div>
    <?php endif; ?>
</div>

<?php if ($schritt !== 'eingeloggt'): ?>

<!-- ════════════ LOGIN ════════════ -->

<div class="login-wrap">
    <div class="login-card">

```
    <div class="login-icon"><?= $schritt === 'code' ? '📬' : '🔐' ?></div>
    <div class="login-title">
        <?= $schritt === 'code' ? 'Code eingeben' : 'Admin-Bereich' ?>
    </div>
    <div class="login-sub">
        <?php if ($schritt === 'code'): ?>
            Wir haben einen 6-stelligen Code an <strong><?= htmlspecialchars(ADMIN_EMAIL) ?></strong> gesendet.
            Der Code ist <?= CODE_GUELTIG / 60 ?> Minuten gültig.
        <?php else: ?>
            Gib deine Admin-E-Mail ein. Du erhältst dann einen Einmal-Code.
        <?php endif; ?>
    </div>

    <!-- Step indicator -->
    <div class="step-indicator">
        <div class="step-dot <?= $schritt === 'email' ? 'active' : 'done' ?>"></div>
        <div class="step-dot <?= $schritt === 'code' ? 'active' : ($schritt === 'eingeloggt' ? 'done' : '') ?>"></div>
    </div>

    <?php if (isset($_GET['timeout'])): ?>
        <div class="alert alert-info">⏱ Deine Session ist abgelaufen. Bitte neu einloggen.</div>
    <?php endif; ?>

    <?php if ($fehler): ?>
        <div class="alert alert-error">⚠️ <?= htmlspecialchars($fehler) ?></div>
    <?php endif; ?>

    <?php if ($erfolg): ?>
        <div class="alert alert-success">✅ <?= htmlspecialchars($erfolg) ?></div>
    <?php endif; ?>

    <!-- ── SCHRITT 1: E-Mail ── -->
    <?php if ($schritt === 'email'): ?>
    <form method="POST" autocomplete="off">
        <input type="hidden" name="aktion" value="email_senden">
        <div style="margin-bottom:16px;">
            <label>E-Mail-Adresse</label>
            <input type="email" name="email" placeholder="deine@email.de"
                   required autofocus autocomplete="email">
        </div>
        <button type="submit" class="btn">Code anfordern →</button>
    </form>

    <!-- ── SCHRITT 2: Code ── -->
    <?php elseif ($schritt === 'code'): ?>
    <form method="POST" autocomplete="off">
        <input type="hidden" name="aktion" value="code_pruefen">
        <div style="margin-bottom:16px;">
            <label>6-stelliger Code</label>
            <input type="text" name="code" class="code-input"
                   maxlength="6" pattern="\d{6}" placeholder="000000"
                   required autofocus autocomplete="one-time-code"
                   inputmode="numeric">
        </div>
        <button type="submit" class="btn btn-gold">Einloggen ✓</button>
    </form>
    <a class="back-link" href="admin.php">← Andere E-Mail verwenden</a>
    <?php endif; ?>

</div>
```

</div>

<?php else: ?>

<!-- ════════════ DASHBOARD ════════════ -->

<div class="dashboard">

```
<div class="dash-header">
    <div class="dash-title">Website-Inhalte bearbeiten</div>
    <div class="dash-sub">Änderungen werden sofort auf der Website sichtbar. Klicke auf „Speichern" um sie zu übernehmen.</div>
</div>

<?php if ($fehler): ?>
    <div class="alert alert-error">⚠️ <?= htmlspecialchars($fehler) ?></div>
<?php endif; ?>
<?php if ($erfolg): ?>
    <div class="alert alert-success">✅ <?= htmlspecialchars($erfolg) ?></div>
<?php endif; ?>

<form method="POST" id="edit-form">
    <input type="hidden" name="aktion" value="speichern">

    <!-- HERO BEREICH -->
    <div class="section-card">
        <div class="section-title">Hero-Bereich (oben)</div>
        <div class="span preview-badge">
            🖥️ Wird ganz oben auf der Website angezeigt
        </div>
        <div class="fields-grid">
            <div class="field-group">
                <label>Eyebrow-Text (klein über Überschrift)</label>
                <input type="text" name="eyebrow"
                       value="<?= val('eyebrow', 'Für lokale Betriebe in Deutschland') ?>">
                <div class="field-hint">Kurz, max. 50 Zeichen</div>
            </div>
            <div class="field-group">
                <label>Telefonnummer</label>
                <input type="text" name="phone"
                       value="<?= val('phone', '+49 XXX – XXX XX XX') ?>">
                <div class="field-hint">Wird auch als klickbarer Link verwendet</div>
            </div>
            <div class="field-group">
                <label>Überschrift – Zeile 1</label>
                <input type="text" name="hero-line1"
                       value="<?= val('hero-line1', 'Ihre Website.') ?>">
            </div>
            <div class="field-group">
                <label>Überschrift – Zeile 2</label>
                <input type="text" name="hero-line2"
                       value="<?= val('hero-line2', 'Modern. Fertig.') ?>">
            </div>
            <div class="field-group full">
                <label>Überschrift – Zeile 3 (farbig hervorgehoben)</label>
                <input type="text" name="hero-line3"
                       value="<?= val('hero-line3', 'Bezahlbar.') ?>">
            </div>
            <div class="field-group full">
                <label>Untertitel / Beschreibung</label>
                <textarea name="hero-sub" rows="3"
                    style="resize:vertical;"><?= val('hero-sub', 'Wir modernisieren veraltete Websites für Handwerker, Ärzte, Restaurants und lokale Dienstleister – schnell, unkompliziert und ohne Technik-Stress für Sie.') ?></textarea>
            </div>
        </div>
    </div>

    <!-- KONTAKT / CTA -->
    <div class="section-card">
        <div class="section-title">Kontakt & Call-to-Action</div>
        <div class="fields-grid">
            <div class="field-group full">
                <label>CTA-Überschrift (großer Bereich unten)</label>
                <input type="text" name="cta-title"
                       value="<?= val('cta-title', 'Wie würde Ihre Website aussehen?') ?>">
            </div>
            <div class="field-group full">
                <label>CTA-Beschreibungstext</label>
                <textarea name="cta-sub" rows="2"
                    style="resize:vertical;"><?= val('cta-sub', 'Wir erstellen eine kostenlose Vorschau Ihrer neuen Website – in 48 Stunden, unverbindlich und ohne Risiko.') ?></textarea>
            </div>
            <div class="field-group">
                <label>Telefon im CTA-Bereich</label>
                <input type="text" name="cta-phone"
                       value="<?= val('cta-phone', '+49 XXX – XXX XX XX') ?>">
            </div>
        </div>
    </div>

    <!-- SAVE BAR -->
    <div class="save-bar">
        <div class="save-bar-info">
            💡 Tipp: Nach dem Speichern die Website im Browser neu laden um die Änderungen zu sehen.
        </div>
        <button type="submit" class="btn btn-gold btn-sm" style="width:auto;">
            💾 Alle Änderungen speichern
        </button>
    </div>
</form>

<!-- DANGER ZONE -->
<div class="section-card danger-zone" style="margin-top:40px;">
    <div class="section-title">Zurücksetzen</div>
    <p style="font-size:14px;color:#6b7280;margin-bottom:16px;">
        Alle Textänderungen werden gelöscht und die Website zeigt wieder die Original-Texte.
        Dieser Vorgang kann nicht rückgängig gemacht werden.
    </p>
    <a href="admin.php?reset=1"
       onclick="return confirm('Wirklich alle Änderungen zurücksetzen?')"
       class="btn btn-sm"
       style="display:inline-block;background:#dc2626;text-decoration:none;text-align:center;color:#fff;padding:9px 20px;border-radius:7px;font-weight:700;font-size:13px;">
        ↩ Auf Original zurücksetzen
    </a>
</div>
```

</div><!-- /dashboard -->
<?php endif; ?>

</body>
</html>
