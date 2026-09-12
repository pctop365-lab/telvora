<?php

declare(strict_types=1);

require_once __DIR__ . '/runtime_config.php';

function telvoraPublicContacts(): array
{
    $contacts = json_decode((string)file_get_contents(__DIR__ . '/public_contacts.json'), true);
    if (!is_array($contacts) || !is_string($contacts['supportEmail'] ?? null) || trim($contacts['supportEmail']) === '') {
        throw new RuntimeException('Public contact configuration is unavailable.');
    }
    return $contacts;
}

function telvoraCallbackValidate(array $input, ?int $nowMs = null): array
{
    $name = trim((string)($input['name'] ?? ''));
    $phone = trim((string)($input['phone'] ?? ''));
    $preferredTime = trim((string)($input['preferred_time'] ?? ''));
    $consent = ($input['consent'] ?? false) === true;
    $company = trim((string)($input['company'] ?? ''));
    $startedAt = filter_var($input['started_at'] ?? null, FILTER_VALIDATE_INT);
    $nowMs ??= (int)floor(microtime(true) * 1000);

    if ($company !== '') throw new InvalidArgumentException('Не удалось принять заявку.');
    if (!$consent) throw new InvalidArgumentException('Необходимо согласие на обработку персональных данных.');
    if (mb_strlen($name) < 2 || mb_strlen($name) > 80 || preg_match('/[\x00-\x1F\x7F]/u', $name)) throw new InvalidArgumentException('Проверьте имя.');
    $phoneDigits = preg_replace('/\D+/', '', $phone) ?? '';
    if (strlen($phoneDigits) < 10 || strlen($phoneDigits) > 15 || mb_strlen($phone) > 32) throw new InvalidArgumentException('Проверьте номер телефона.');
    if (mb_strlen($preferredTime) < 2 || mb_strlen($preferredTime) > 120 || preg_match('/[\x00-\x1F\x7F]/u', $preferredTime)) throw new InvalidArgumentException('Проверьте удобное время звонка.');
    if ($startedAt === false || $startedAt > $nowMs || $nowMs - $startedAt < 1500 || $nowMs - $startedAt > 86400000) throw new InvalidArgumentException('Обновите страницу и повторите отправку.');

    return ['name' => $name, 'phone' => $phone, 'preferred_time' => $preferredTime];
}

function telvoraCallbackRateLimit(string $file, string $clientKey, ?int $now = null): bool
{
    $now ??= time();
    $window = 15 * 60;
    $handle = @fopen($file, 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) return false;
    try {
        $raw = stream_get_contents($handle);
        $entries = is_string($raw) ? json_decode($raw, true) : [];
        if (!is_array($entries)) $entries = [];
        foreach ($entries as $key => $timestamps) {
            $entries[$key] = array_values(array_filter(is_array($timestamps) ? $timestamps : [], static fn($value) => is_int($value) && $value > $now - $window));
            if ($entries[$key] === []) unset($entries[$key]);
        }
        $recent = $entries[$clientKey] ?? [];
        $globalCount = array_sum(array_map('count', $entries));
        if (count($recent) >= 3 || $globalCount >= 60) return false;
        $recent[] = $now;
        $entries[$clientKey] = $recent;
        rewind($handle); ftruncate($handle, 0);
        if (fwrite($handle, json_encode($entries, JSON_UNESCAPED_SLASHES)) === false) return false;
        fflush($handle);
        return true;
    } finally {
        flock($handle, LOCK_UN); fclose($handle);
    }
}

function telvoraCallbackSend(array $request, array $contacts, array $secrets): bool
{
    $from = $secrets['callback_mail_from'] ?? '';
    if (!is_string($from) || !filter_var($from, FILTER_VALIDATE_EMAIL)) return false;
    $subject = '=?UTF-8?B?' . base64_encode('TELVORA: заявка на обратный звонок') . '?=';
    $body = "Новая заявка на обратный звонок\n\nИмя: {$request['name']}\nТелефон: {$request['phone']}\nУдобное время: {$request['preferred_time']}\nПолучено: " . date(DATE_ATOM) . "\n";
    return mail($contacts['supportEmail'], $subject, $body, [
        'From' => 'TELVORA <' . $from . '>',
        'Content-Type' => 'text/plain; charset=UTF-8',
        'X-Mailer' => 'TELVORA callback form',
    ]);
}

function telvoraCallbackJson(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!defined('TELVORA_CALLBACK_LIBRARY_ONLY')) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') telvoraCallbackJson(405, ['success' => false, 'message' => 'Метод не поддерживается.']);
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192) telvoraCallbackJson(413, ['success' => false, 'message' => 'Запрос слишком большой.']);
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (!str_starts_with($contentType, 'application/json')) telvoraCallbackJson(415, ['success' => false, 'message' => 'Неверный формат запроса.']);
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && !in_array($origin, ['https://telvora.ru', 'https://www.telvora.ru'], true)) telvoraCallbackJson(403, ['success' => false, 'message' => 'Запрос отклонён.']);

    try {
        $input = json_decode((string)file_get_contents('php://input'), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($input)) throw new JsonException('Invalid body');
        $request = telvoraCallbackValidate($input);
        $clientKey = hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $rateFile = telvoraRuntimeFile('callback_rate_limit.json');
        if ($rateFile === '' || !telvoraCallbackRateLimit($rateFile, $clientKey)) telvoraCallbackJson(429, ['success' => false, 'message' => 'Слишком много попыток. Повторите позже.']);
        $secrets = require telvoraSecretsFile();
        if (!is_array($secrets) || !telvoraCallbackSend($request, telvoraPublicContacts(), $secrets)) telvoraCallbackJson(503, ['success' => false, 'message' => 'Сейчас не удалось принять заявку. Позвоните или напишите нам.']);
        telvoraCallbackJson(200, ['success' => true, 'message' => 'Заявка принята.']);
    } catch (InvalidArgumentException $error) {
        telvoraCallbackJson(422, ['success' => false, 'message' => $error->getMessage()]);
    } catch (JsonException $error) {
        telvoraCallbackJson(400, ['success' => false, 'message' => 'Неверный формат запроса.']);
    } catch (Throwable $error) {
        error_log('TELVORA callback request failed: ' . $error->getMessage());
        telvoraCallbackJson(500, ['success' => false, 'message' => 'Сейчас не удалось принять заявку. Позвоните или напишите нам.']);
    }
}
