<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
flora_start_session();
header('Content-Type: image/svg+xml; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$chars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
$len = 5;
$code = '';
for ($i = 0; $i < $len; $i++) {
    $code .= $chars[random_int(0, strlen($chars) - 1)];
}
$_SESSION['register_captcha_code'] = $code;

$lines = '';
for ($i = 0; $i < 6; $i++) {
    $x1 = random_int(0, 180);
    $y1 = random_int(0, 60);
    $x2 = random_int(0, 180);
    $y2 = random_int(0, 60);
    $opacity = random_int(18, 35) / 100;
    $lines .= '<line x1="'.$x1.'" y1="'.$y1.'" x2="'.$x2.'" y2="'.$y2.'" stroke="#0f766e" stroke-width="1.2" opacity="'.$opacity.'" />';
}

$dots = '';
for ($i = 0; $i < 28; $i++) {
    $cx = random_int(4, 176);
    $cy = random_int(4, 56);
    $r = random_int(1, 2);
    $opacity = random_int(15, 30) / 100;
    $dots .= '<circle cx="'.$cx.'" cy="'.$cy.'" r="'.$r.'" fill="#334155" opacity="'.$opacity.'" />';
}

$text = '';
$x = 18;
for ($i = 0; $i < strlen($code); $i++) {
    $char = htmlspecialchars($code[$i], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $y = random_int(38, 46);
    $rotate = random_int(-18, 18);
    $size = random_int(26, 30);
    $text .= '<text x="'.$x.'" y="'.$y.'" font-size="'.$size.'" font-weight="800" fill="#0f172a" transform="rotate('.$rotate.' '.$x.' '.$y.')">'.$char.'</text>';
    $x += 30;
}

echo <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="180" height="60" viewBox="0 0 180 60" role="img" aria-label="Код подтверждения">
  <rect width="180" height="60" rx="14" fill="#f8fafc" />
  <rect x="1" y="1" width="178" height="58" rx="13" fill="none" stroke="#dbe4dd" />
  {$lines}
  {$dots}
  <g font-family="Arial, Helvetica, sans-serif" letter-spacing="2">{$text}</g>
</svg>
SVG;
