<?php
/**
 * Adoptă o scrisoare · primește formularele site-ului
 * - trimite fiecare înscriere la adresa campaniei
 * - trimite automat un e-mail de confirmare celui care a completat formularul
 * - păstrează o copie în date/inregistrari.csv
 * Necesită PHP 7.4+ cu funcția mail() activă (standard pe găzduirile cPanel).
 */
header('Content-Type: application/json; charset=utf-8');

$CFG = [
  'to'       => 'moscraciun@grupulverde.ro',
  'from'     => 'moscraciun@grupulverde.ro',
  'fromName' => 'Adoptă o scrisoare · Asociația Grupul Verde',
  'site'     => 'https://grupulverde.ro/adopta-o-scrisoare',
  'whatsapp' => 'https://wa.me/40374962748',
];

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok' => false]); exit; }

$raw = file_get_contents('php://input');
$d = json_decode($raw, true);
if (!is_array($d)) { $d = $_POST; }
if (!empty($d['_hp'])) { echo json_encode(['ok' => true]); exit; }

// limită simplă: maximum 15 trimiteri pe oră de la aceeași adresă IP
$ip = $_SERVER['REMOTE_ADDR'] ?? '0';
$dir = __DIR__ . '/date';
if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
$rl = $dir . '/.rl_' . md5($ip);
$hits = array_filter(array_map('intval', @file($rl, FILE_IGNORE_NEW_LINES) ?: []), fn($t) => $t > time() - 3600);
if (count($hits) >= 15) { http_response_code(429); echo json_encode(['ok' => false, 'error' => 'rate']); exit; }
$hits[] = time(); @file_put_contents($rl, implode("\n", $hits));

$subject = preg_replace('/[\r\n]+/', ' ', trim((string)($d['_subject'] ?? 'Formular Adoptă o scrisoare')));
unset($d['_to'], $d['_subject'], $d['_hp']);

$c = [];
foreach ($d as $k => $v) {
  if (is_array($v)) { $v = implode(', ', $v); }
  $k = preg_replace('/[^\p{L}\p{N}_ -]/u', '', (string)$k);
  $v = trim(strip_tags((string)$v));
  if ($k === '' || $v === '') { continue; }
  $c[$k] = mb_substr($v, 0, 2000);
}
$email = $c['Email'] ?? '';
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { http_response_code(422); echo json_encode(['ok' => false, 'error' => 'email']); exit; }

$lines = [];
foreach ($c as $k => $v) { $lines[] = str_replace('_', ' ', $k) . ': ' . $v; }
$body = "Formular nou primit pe site\n\n" . implode("\n", $lines) . "\n\nData: " . date('d.m.Y H:i') . "\nIP: " . $ip;
$sent = send_mail($CFG['to'], $subject, $body, $CFG, $email);

$f = @fopen($dir . '/inregistrari.csv', 'a');
if ($f) { fputcsv($f, [date('c'), $subject, json_encode($c, JSON_UNESCAPED_UNICODE)]); fclose($f); }

if ($email !== '') { [$s, $b] = auto_reply($subject, $c, $CFG); send_mail($email, $s, $b, $CFG, $CFG['to']); }

echo json_encode(['ok' => (bool)$sent]);

function enc_h($s) { return '=?UTF-8?B?' . base64_encode($s) . '?='; }
function send_mail($to, $subject, $body, $CFG, $replyTo = '') {
  $h  = "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n";
  $h .= "From: " . enc_h($CFG['fromName']) . " <" . $CFG['from'] . ">\r\n";
  if ($replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) { $h .= "Reply-To: " . $replyTo . "\r\n"; }
  return @mail($to, enc_h($subject), $body, $h, '-f' . $CFG['from']);
}
function auto_reply($subject, $c, $CFG) {
  $pren = trim(explode(' ', trim($c['Nume'] ?? ''))[0] ?? '');
  $pren = $pren !== '' ? $pren : 'prietene';
  $sig = "\n\nCu recunoștință,\nEchipa Adoptă o scrisoare\nAsociația Grupul Verde din Adjud, Vrancea\n" . $CFG['site'] . "\nWhatsApp: " . $CFG['whatsapp'];
  if (stripos($subject, 'Ambasador') === 0) {
    $cod = $c['Cod'] ?? '';
    return ["Bine ai venit, Ambasadorule al Crăciunului!",
      "Dragă $pren,\n\nDe azi ești Ambasadorul Crăciunului pentru copiii din Vrancea. Codul tău este $cod, iar linkul tău personal este:\n" . $CFG['site'] . "?amb=$cod\n\nTrei pași pentru prima săptămână:\n1. Trimite linkul la 10 oameni apropiați, cu un mesaj personal.\n2. Postează o dată povestea campaniei.\n3. Spune-ne unde poți duce campania: școală, birou, comunitate.\n\nÎți scriem în fiecare luni câte scrisori au fost adoptate prin tine." . $sig];
  }
  $scr = $c['Scrisoare'] ?? 'scrisoarea aleasă';
  $mod = $c['Modalitate'] ?? '';
  if (stripos($mod, 'Cumpăr') !== false) {
    $liv = $c['Livrare'] ?? '';
    $term = stripos($liv, 'FAN') !== false ? "Trimite coletul prin FAN Courier până pe 8 decembrie. Instrucțiunile de expediere le primești separat." : "Adu pachetul la punctul de colectare din Adjud până pe 10 decembrie. Adresa și programul le primești separat.";
    return ["$pren, scrisoarea are acum un Moș Crăciun",
      "Dragă $pren,\n\nÎți mulțumim că ai ales să răspunzi scrisorii $scr. Din acest moment, scrisoarea e rezervată pentru tine.\n\nCe urmează:\n1. Cumpără darurile din scrisoare.\n2. Împachetează-le și scrie pe pachet codul scrisorii.\n3. $term\n\nPe 20 decembrie, voluntarii noștri duc darul copilului. După livrare îți trimitem fotografia momentului." . $sig];
  }
  $suma = $c['Suma'] ?? '';
  return ["Mulțumim, $pren! Contribuția ta îmbracă un copil",
    "Dragă $pren,\n\nAm primit înscrierea ta pentru $scr ($mod, $suma).\nÎți trimitem în scurt timp datele pentru plată și confirmarea.\n\nVoluntarii noștri cumpără și împachetează darurile, iar pe 20 decembrie pachetul ajunge la copil. După livrare primești fotografia momentului." . $sig];
}
