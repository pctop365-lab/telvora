<?php

declare(strict_types=1);

define('TELVORA_CALLBACK_LIBRARY_ONLY', true);
require_once dirname(__DIR__) . '/callback_request.php';

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function rejects(array $input, int $nowMs): bool
{
    try { telvoraCallbackValidate($input, $nowMs); return false; }
    catch (InvalidArgumentException $error) { return true; }
}

$nowMs = 2_000_000;
$valid = ['name' => 'Анна', 'phone' => '+7 (926) 202-01-19', 'preferred_time' => 'Сегодня после 18:00', 'consent' => true, 'company' => '', 'started_at' => $nowMs - 3000];
$clean = telvoraCallbackValidate($valid, $nowMs);
check($clean['name'] === 'Анна', 'valid request rejected');
check(rejects([...$valid, 'consent' => false], $nowMs), 'missing consent accepted');
check(rejects([...$valid, 'phone' => '123'], $nowMs), 'invalid phone accepted');
check(rejects([...$valid, 'company' => 'spam'], $nowMs), 'honeypot accepted');
check(rejects([...$valid, 'started_at' => $nowMs - 100], $nowMs), 'instant submission accepted');

$rateFile = tempnam(sys_get_temp_dir(), 'telvora-callback-');
check(is_string($rateFile), 'temporary rate file unavailable');
try {
    check(telvoraCallbackRateLimit($rateFile, 'client', 1000), 'first request blocked');
    check(telvoraCallbackRateLimit($rateFile, 'client', 1001), 'second request blocked');
    check(telvoraCallbackRateLimit($rateFile, 'client', 1002), 'third request blocked');
    check(!telvoraCallbackRateLimit($rateFile, 'client', 1003), 'fourth request was not rate-limited');
    check(telvoraCallbackRateLimit($rateFile, 'client', 2000), 'expired window was not cleared');
} finally {
    @unlink($rateFile);
}

$contacts = telvoraPublicContacts();
check($contacts['supportEmail'] === 'telvorasupport24@gmail.com', 'support recipient mismatch');
$pdf = file_get_contents(dirname(__DIR__) . '/generate_invoice_pdf.php');
check(is_string($pdf) && str_contains($pdf, 'Почта для заказов') && str_contains($pdf, 'Почта поддержки'), 'invoice contact labels missing');

echo "callback_request_service_test: PASS\n";
