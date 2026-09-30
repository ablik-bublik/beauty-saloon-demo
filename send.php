<?php
/**
 * Обработчик формы онлайн-записи
 * Отправка в Telegram + создание события в Google Calendar
 */

// ================== НАСТРОЙКИ ==================
// Telegram
define('TELEGRAM_BOT_TOKEN', '8926509336:AAGI_YDddEdQLSR6E5QZlvCLdX45UNfTi1I');
define('TELEGRAM_CHAT_ID',   '600416976');

// Email (для дублирования)
define('ADMIN_EMAIL', '');
define('SITE_DOMAIN', 'asem-saloon.kz');

// Google Calendar
define('GOOGLE_CALENDAR_ID', 'abc123def456@group.calendar.google.com'); // из шага 3
define('GOOGLE_CREDENTIALS_FILE', __DIR__ . '/google-credentials.json'); // из шага 5
define('GOOGLE_TIMEZONE', 'Asia/Almaty');

// Длительность услуги по умолчанию (в минутах), если не указана в форме
define('DEFAULT_DURATION', 60);

// Если у вас несколько мастеров — сопоставьте имя мастера с календарём
// Пока у всех один календарь, но можно расширить
$MASTER_CALENDARS = [
    'Айгерим Н. — парикмахер' => GOOGLE_CALENDAR_ID,
    'Динара К. — маникюр'     => GOOGLE_CALENDAR_ID,
    'Жанар А. — косметолог'   => GOOGLE_CALENDAR_ID,
    'Мадина С. — бровист'     => GOOGLE_CALENDAR_ID,
    'Любой свободный'         => GOOGLE_CALENDAR_ID,
];
// =================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

// Honeypot
if (!empty($_POST['website'])) {
    echo json_encode(['status' => 'ok']);
    exit;
}

// Очистка данных
function clean($key, $maxLen = 200) {
    $val = trim($_POST[$key] ?? '');
    $val = strip_tags($val);
    return mb_substr($val, 0, $maxLen);
}

$name    = clean('name', 60);
$phone   = clean('phone', 20);
$service = clean('service', 100);
$master  = clean('master', 100);
$date    = clean('date', 20);
$time    = clean('time', 10);
$comment = clean('comment', 500);
$agree   = isset($_POST['agree']);

// Валидация
$errors = [];
if (mb_strlen($name) < 2) $errors[] = 'Имя слишком короткое';

$digits = preg_replace('/\D/', '', $phone);
if (strlen($digits) !== 11 || $digits[0] !== '7') $errors[] = 'Некорректный телефон';
if (empty($service)) $errors[] = 'Не выбрана услуга';

if (empty($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $errors[] = 'Некорректная дата';
} elseif ($date < date('Y-m-d')) {
    $errors[] = 'Дата уже прошла';
}

if (empty($time)) $errors[] = 'Не выбрано время';
if (!$agree) $errors[] = 'Нужно согласие на обработку данных';

if ($errors) {
    echo json_encode(['status' => 'error', 'message' => implode('. ', $errors)]);
    exit;
}

// Форматирование
$phoneFormatted = '+7 (' . substr($digits, 1, 3) . ') ' . substr($digits, 4, 3) . '-' . substr($digits, 7, 2) . '-' . substr($digits, 9, 2);
$dateFormatted  = date('d.m.Y', strtotime($date));
$bookingId      = date('ymd') . '-' . rand(100, 999);

// ================== 1. TELEGRAM ==================
function sendToTelegram($text) {
    $url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage';
    $data = [
        'chat_id' => TELEGRAM_CHAT_ID,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code === 200;
    }
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query($data),
        'timeout' => 10,
    ]]);
    return @file_get_contents($url, false, $ctx) !== false;
}

$tgText = "🆕 <b>НОВАЯ ЗАПИСЬ #{$bookingId}</b>\n\n"
        . "👤 <b>Имя:</b> {$name}\n"
        . "📞 <b>Телефон:</b> {$phoneFormatted}\n"
        . "💅 <b>Услуга:</b> {$service}\n"
        . "✂️ <b>Мастер:</b> {$master}\n"
        . "📅 <b>Дата:</b> {$dateFormatted}\n"
        . "🕐 <b>Время:</b> {$time}\n";
if (!empty($comment)) $tgText .= "💬 <b>Комментарий:</b> {$comment}\n";
$tgText .= "\n🌐 Источник: " . SITE_DOMAIN;

$telegramOk = sendToTelegram($tgText);

// ================== 2. GOOGLE CALENDAR ==================

/**
 * Получить access_token через Service Account
 * Токен действует 1 час, при каждом запросе получаем новый
 */
function getGoogleAccessToken() {
    if (!file_exists(GOOGLE_CREDENTIALS_FILE)) {
        return null;
    }
    
    $creds = json_decode(file_get_contents(GOOGLE_CREDENTIALS_FILE), true);
    if (!$creds || empty($creds['client_email']) || empty($creds['private_key'])) {
        return null;
    }

    $now = time();
    $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
    $payload = json_encode([
        'iss' => $creds['client_email'],
        'scope' => 'https://www.googleapis.com/auth/calendar',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600,
    ]);

    $base64UrlEncode = function($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    };

    $signatureInput = $base64UrlEncode($header) . '.' . $base64UrlEncode($payload);

    $signature = '';
    if (!openssl_sign($signatureInput, $signature, $creds['private_key'], 'SHA256')) {
        return null;
    }
    $jwt = $signatureInput . '.' . $base64UrlEncode($signature);

    // Обмен JWT на access_token
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    return $data['access_token'] ?? null;
}

/**
 * Создать событие в Google Calendar
 * @return string|null — ссылка на событие или null при ошибке
 */
function createCalendarEvent($accessToken, $calendarId, $booking) {
    $startDateTime = $booking['date'] . 'T' . $booking['time'] . ':00';
    $startTs = strtotime($startDateTime);
    if (!$startTs) return null;

    $endTs = $startTs + ($booking['duration'] * 60);
    
    $event = [
        'summary' => "{$booking['service']} — {$booking['name']}",
        'description' => "📞 {$booking['phone']}\n"
                       . "💅 Услуга: {$booking['service']}\n"
                       . "✂️ Мастер: {$booking['master']}\n"
                       . "💬 {$booking['comment']}\n\n"
                       . "ID записи: {$booking['id']}",
        'start' => [
            'dateTime' => date('c', $startTs),
            'timeZone' => GOOGLE_TIMEZONE,
        ],
        'end' => [
            'dateTime' => date('c', $endTs),
            'timeZone' => GOOGLE_TIMEZONE,
        ],
        'reminders' => [
            'useDefault' => false,
            'overrides' => [
                ['method' => 'popup', 'minutes' => 30],
                ['method' => 'email', 'minutes' => 60],
            ],
        ],
        'colorId' => '4', // 4 = Flamingo (розовый)
    ];

    $url = 'https://www.googleapis.com/calendar/v3/calendars/'
         . urlencode($calendarId) . '/events';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($event, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200 || $code === 201) {
        $data = json_decode($response, true);
        return $data['htmlLink'] ?? null;
    }
    
    // Логируем ошибку
    @file_put_contents(__DIR__ . '/calendar-error.log',
        date('Y-m-d H:i:s') . " | HTTP $code | $response\n",
        FILE_APPEND | LOCK_EX);
    
    return null;
}

// Определяем календарь для мастера
$calendarId = $MASTER_CALENDARS[$master] ?? GOOGLE_CALENDAR_ID;

// Создаём событие
$calendarLink = null;
$accessToken = getGoogleAccessToken();

if ($accessToken) {
    $calendarLink = createCalendarEvent($accessToken, $calendarId, [
        'id' => $bookingId,
        'name' => $name,
        'phone' => $phoneFormatted,
        'service' => $service,
        'master' => $master,
        'date' => $date,
        'time' => $time,
        'comment' => $comment,
        'duration' => DEFAULT_DURATION,
    ]);
}

// Если событие создано — обновляем сообщение в Telegram со ссылкой
if ($calendarLink) {
    sendToTelegram("📅 <b>Событие добавлено в календарь мастера</b>\n"
                 . "🔗 <a href=\"{$calendarLink}\">Открыть в Google Calendar</a>");
}

// ================== 3. EMAIL ==================
$emailOk = true;
if (!empty(ADMIN_EMAIL)) {
    $subject = "=?UTF-8?B?" . base64_encode("Новая запись #{$bookingId} — {$name}") . "?=";
    $body = "Новая запись в салон Әсем\n========================\n\n"
          . "ID: {$bookingId}\nИмя: {$name}\nТелефон: {$phoneFormatted}\n"
          . "Услуга: {$service}\nМастер: {$master}\nДата: {$dateFormatted}\nВремя: {$time}\n"
          . "Комментарий: {$comment}\n";
    if ($calendarLink) {
        $body .= "\nСобытие в календаре: {$calendarLink}\n";
    }
    $headers = "From: Салон Әсем <noreply@" . SITE_DOMAIN . ">\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\nMIME-Version: 1.0\r\n";
    $emailOk = @mail(ADMIN_EMAIL, $subject, $body, $headers);
}

// ================== 4. ЛОГ ==================
$logLine = date('Y-m-d H:i:s') . " | #{$bookingId} | {$name} | {$phoneFormatted} | "
         . "{$service} | {$date} {$time} | TG:" . ($telegramOk ? 'OK' : 'FAIL')
         . " | CAL:" . ($calendarLink ? 'OK' : 'FAIL') . "\n";
@file_put_contents(__DIR__ . '/bookings.log', $logLine, FILE_APPEND | LOCK_EX);

// ================== ОТВЕТ ==================
if ($telegramOk || $emailOk) {
    echo json_encode([
        'status' => 'ok',
        'booking_id' => $bookingId,
        'calendar' => $calendarLink ? 'created' : 'skipped',
    ]);
} else {
    echo json_encode([
        'status' => 'error',
        'message' => 'Не удалось отправить. Позвоните: +7 (727) 123-45-67'
    ]);
}
