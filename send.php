<?php
/**
 * Camp registration form mailer
 * Requires PHPMailer — upload vendor/ folder alongside this file
 * Install locally: composer require phpmailer/phpmailer
 *
 * World4You SMTP: smtp.world4you.com  port 587  STARTTLS
 */

// CORS — send headers first so errors are always readable cross-origin
$allowed = [
    'https://rusgymnasium.github.io',
    'https://camp.rusgymnasium.at',
    'https://www.rusgymnasium.at',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed, true)) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

// Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Load PHPMailer
$autoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'vendor/ folder missing on server']);
    exit;
}
require $autoload;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// --- Config ---------------------------------------------------------------
define('SMTP_HOST',  'smtp.world4you.com');
define('SMTP_PORT',  587);
define('SMTP_USER',  'info@rusgymnasium.at');
define('SMTP_PASS',  'ma=dery9ry');
define('MAIL_TO',    'info@rusgymnasium.at');
define('MAIL_FROM',  'info@rusgymnasium.at');
define('MAIL_NAME',  'Лагерь Меридиан');
// --------------------------------------------------------------------------

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// --- Anti-spam ---------------------------------------------------------------

// 1. Honeypot — bots fill hidden fields, humans don't
if (!empty($_POST['website'])) {
    http_response_code(200); // lie to bots
    echo json_encode(['success' => true]);
    exit;
}

// 2. Timing check — reject if form submitted in under 3 seconds
$ts = isset($_POST['form_ts']) ? (int)$_POST['form_ts'] : 0;
if ($ts === 0 || (time() - $ts) < 3) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too fast']);
    exit;
}

// 3. Rate limit — max 5 submissions per IP per hour
$ip       = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateFile = sys_get_temp_dir() . '/camp_rl_' . md5($ip) . '.json';
$now      = time();
$window   = 3600;
$limit    = 5;

$log = [];
if (file_exists($rateFile)) {
    $log = json_decode(file_get_contents($rateFile), true) ?: [];
}
$log = array_filter($log, fn($t) => ($now - $t) < $window);
if (count($log) >= $limit) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too many requests']);
    exit;
}
$log[] = $now;
file_put_contents($rateFile, json_encode(array_values($log)));

// -----------------------------------------------------------------------------

// Sanitise helper
function clean(string $v): string {
    return htmlspecialchars(trim($v), ENT_QUOTES, 'UTF-8');
}

// Required fields
$required = ['parent_name', 'child_name', 'child_age', 'phone', 'email'];
foreach ($required as $field) {
    if (empty($_POST[$field])) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => "Missing field: $field"]);
        exit;
    }
}

$parentName = clean($_POST['parent_name']);
$childName  = clean($_POST['child_name']);
$childAge   = clean($_POST['child_age']);
$phone      = clean($_POST['phone']);
$email      = filter_var(trim($_POST['email']), FILTER_VALIDATE_EMAIL);
$weeks      = isset($_POST['weeks']) && is_array($_POST['weeks'])
              ? array_map('clean', $_POST['weeks'])
              : [];
$notes      = clean($_POST['notes'] ?? '');

if (!$email) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid email address']);
    exit;
}

$weeksStr = !empty($weeks) ? implode(', ', $weeks) : 'не указано';

// Build email body
$body = "
<html><head><meta charset='utf-8'>
<style>
  body { font-family: 'Open Sans', Arial, sans-serif; color: #1a1a1a; background: #fafafa; margin: 0; padding: 0; }
  .wrap { max-width: 560px; margin: 32px auto; background: #fff; border: 1px solid #e5e5e5; }
  .head { background: #ed7002; padding: 28px 32px; }
  .head h1 { color: #fff; font-size: 18px; font-weight: 700; margin: 0; letter-spacing: .04em; text-transform: uppercase; }
  .head p  { color: rgba(255,255,255,.75); font-size: 13px; margin: 4px 0 0; }
  .body { padding: 28px 32px; }
  table { width: 100%; border-collapse: collapse; }
  td { padding: 10px 0; border-bottom: 1px solid #f0f0f0; font-size: 14px; vertical-align: top; }
  td:first-child { width: 38%; color: #888; font-size: 11px; text-transform: uppercase; letter-spacing: .1em; padding-right: 12px; padding-top: 13px; }
  td:last-child { font-weight: 500; }
  .notes td:last-child { font-weight: 400; color: #555; white-space: pre-wrap; }
  .foot { background: #f5f5f5; padding: 16px 32px; font-size: 12px; color: #999; }
</style>
</head><body>
<div class='wrap'>
  <div class='head'>
    <h1>Новая заявка в лагерь</h1>
    <p>Летний лагерь «Меридиан» — Вена 2026</p>
  </div>
  <div class='body'>
    <table>
      <tr><td>Родитель</td><td>" . $parentName . "</td></tr>
      <tr><td>Ребёнок</td><td>" . $childName . "</td></tr>
      <tr><td>Возраст</td><td>" . $childAge . "</td></tr>
      <tr><td>Телефон</td><td><a href='tel:" . $phone . "' style='color:#ed7002;text-decoration:none;'>" . $phone . "</a></td></tr>
      <tr><td>Email</td><td><a href='mailto:" . $email . "' style='color:#ed7002;text-decoration:none;'>" . $email . "</a></td></tr>
      <tr><td>Недели</td><td>" . $weeksStr . "</td></tr>
      " . ($notes ? "<tr class='notes'><td>Пожелания</td><td>" . $notes . "</td></tr>" : "") . "
    </table>
  </div>
  <div class='foot'>Отправлено с сайта camp.rusgymnasium.at</div>
</div>
</body></html>
";

// Plain-text fallback
$plain = "Новая заявка — Лагерь Меридиан 2026\n\n"
    . "Родитель:  $parentName\n"
    . "Ребёнок:   $childName\n"
    . "Возраст:   $childAge\n"
    . "Телефон:   $phone\n"
    . "Email:     $email\n"
    . "Недели:    $weeksStr\n"
    . ($notes ? "Пожелания: $notes\n" : "");

$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = SMTP_PORT;
    $mail->CharSet    = 'UTF-8';

    $mail->setFrom(MAIL_FROM, MAIL_NAME);
    $mail->addAddress(MAIL_TO);
    $mail->addReplyTo($email, $parentName);

    $mail->isHTML(true);
    $mail->Subject = "Заявка: $childName ($weeksStr)";
    $mail->Body    = $body;
    $mail->AltBody = $plain;

    $mail->send();

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Mailer error: ' . $mail->ErrorInfo
    ]);
}
