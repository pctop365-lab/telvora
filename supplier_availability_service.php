<?php

declare(strict_types=1);

if (!defined('TELVORA_MANAGER_REQUEST')) {
    http_response_code(404);
    exit;
}

const SUPPLIER_AVAILABILITY_STATUSES = ['in_stock', 'out_of_stock', 'expected', 'unknown'];
const SUPPLIER_ARRIVAL_DATE_FORMATS = ['dmy_dot', 'ymd_dash', 'dmy_slash'];
const SUPPLIER_AVAILABILITY_MAPPING_LIMIT = 500;

function supplierAvailabilityTextLength(string $value): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : (preg_match_all('/./us', $value, $matches) === false ? PHP_INT_MAX : count($matches[0]));
}

function supplierAvailabilityTrim(string $value): string
{
    $trimmed = preg_replace('/\A[\p{Z}\s]+|[\p{Z}\s]+\z/u', '', $value);
    return is_string($trimmed) ? $trimmed : trim($value);
}

function supplierAvailabilityValidateRawMapping(mixed $value): string
{
    if (!is_string($value)) {
        throw new InvalidArgumentException('Значение поставщика должно быть строкой');
    }
    $value = supplierAvailabilityTrim($value);
    $controlMatch = preg_match('/[\x00-\x1F\x7F]/u', $value);
    if ($value === '' || supplierAvailabilityTextLength($value) > 191 || $controlMatch !== 0) {
        throw new InvalidArgumentException('Значение должно содержать от 1 до 191 символа без управляющих знаков');
    }
    return $value;
}

function supplierAvailabilityValidateStatus(mixed $value): string
{
    if (!is_string($value) || !in_array($value, SUPPLIER_AVAILABILITY_STATUSES, true) || $value === 'unknown') {
        throw new InvalidArgumentException('Выберите поддерживаемый статус наличия');
    }
    return $value;
}

function supplierAvailabilityValidateDateFormat(mixed $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_string($value) || !in_array($value, SUPPLIER_ARRIVAL_DATE_FORMATS, true)) {
        throw new InvalidArgumentException('Выберите поддерживаемый формат даты поступления');
    }
    return $value;
}

function supplierAvailabilityLoadMappings(PDO $pdo, int $profileId, bool $forUpdate = false): array
{
    $sql = "SELECT id, import_profile_id, raw_value, raw_value_hash,
                   normalized_status, is_active, created_at, updated_at
            FROM supplier_availability_mappings
            WHERE import_profile_id = :profile_id
            ORDER BY id ASC LIMIT " . (SUPPLIER_AVAILABILITY_MAPPING_LIMIT + 1);
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':profile_id' => $profileId]);
    $rows = $stmt->fetchAll();
    if (count($rows) > SUPPLIER_AVAILABILITY_MAPPING_LIMIT) {
        throw new RuntimeException('supplier availability mapping limit exceeded');
    }
    return $rows;
}

function supplierAvailabilityIndexMappings(array $rows): array
{
    $index = [];
    foreach ($rows as $row) {
        if (!(bool)($row['is_active'] ?? false)) {
            continue;
        }
        $raw = (string)($row['raw_value'] ?? '');
        $status = (string)($row['normalized_status'] ?? '');
        if (!in_array($status, SUPPLIER_AVAILABILITY_STATUSES, true) || $status === 'unknown') {
            throw new RuntimeException('invalid supplier availability mapping status');
        }
        $hash = hash('sha256', $raw);
        if (!hash_equals((string)($row['raw_value_hash'] ?? ''), $hash) || isset($index[$hash])) {
            throw new RuntimeException('invalid or duplicate supplier availability mapping');
        }
        $index[$hash] = ['raw_value' => $raw, 'status' => $status];
    }
    return $index;
}

function supplierAvailabilityWarning(string $code, string $message): array
{
    return ['code' => $code, 'message' => $message];
}

function supplierAvailabilityParseDate(?string $raw, ?string $format, DateTimeImmutable $today): array
{
    if ($raw === null || supplierAvailabilityTrim($raw) === '') {
        return ['value' => null, 'warnings' => []];
    }
    $raw = supplierAvailabilityTrim($raw);
    if ($format === null) {
        return ['value' => null, 'warnings' => [supplierAvailabilityWarning(
            'arrival_format_not_configured',
            'Формат даты поступления не настроен'
        )]];
    }
    $patterns = [
        'dmy_dot' => ['!d.m.Y', '/\A\d{2}\.\d{2}\.\d{4}\z/D'],
        'ymd_dash' => ['!Y-m-d', '/\A\d{4}-\d{2}-\d{2}\z/D'],
        'dmy_slash' => ['!d/m/Y', '/\A\d{2}\/\d{2}\/\d{4}\z/D']
    ];
    if (!isset($patterns[$format]) || preg_match($patterns[$format][1], $raw) !== 1) {
        return ['value' => null, 'warnings' => [supplierAvailabilityWarning('arrival_invalid', 'Дата поступления не соответствует формату профиля')]];
    }
    $date = DateTimeImmutable::createFromFormat($patterns[$format][0], $raw);
    $errors = DateTimeImmutable::getLastErrors();
    if ($date === false || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        return ['value' => null, 'warnings' => [supplierAvailabilityWarning('arrival_invalid', 'Некорректная календарная дата поступления')]];
    }
    $canonical = $date->format('Y-m-d');
    $warnings = [];
    if ($canonical < $today->format('Y-m-d')) {
        $warnings[] = supplierAvailabilityWarning('arrival_in_past', 'Дата ожидаемого поступления уже прошла');
    }
    return ['value' => $canonical . ' 00:00:00', 'warnings' => $warnings];
}

function supplierAvailabilityParseStock(?string $raw): array
{
    if ($raw === null || supplierAvailabilityTrim($raw) === '') {
        return ['value' => null, 'warnings' => []];
    }
    $raw = supplierAvailabilityTrim($raw);
    if (preg_match('/\A\d+\z/D', $raw) !== 1 || strlen($raw) > 10 || (strlen($raw) === 10 && strcmp($raw, '4294967295') > 0)) {
        return ['value' => null, 'warnings' => [supplierAvailabilityWarning('stock_invalid', 'Количество не является допустимым неотрицательным целым числом')]];
    }
    return ['value' => (int)$raw, 'warnings' => []];
}

function normalizeSupplierAvailability(
    array $profile,
    ?string $rawAvailability,
    ?string $rawArrival,
    ?string $rawStock,
    array $mappingRows,
    ?DateTimeImmutable $today = null
): array {
    $today ??= new DateTimeImmutable('today');
    $status = 'unknown';
    $warnings = [];
    $trimmedAvailability = $rawAvailability === null ? '' : supplierAvailabilityTrim($rawAvailability);
    if ($trimmedAvailability !== '') {
        $index = supplierAvailabilityIndexMappings($mappingRows);
        $hash = hash('sha256', $trimmedAvailability);
        if (isset($index[$hash]) && hash_equals($index[$hash]['raw_value'], $trimmedAvailability)) {
            $status = $index[$hash]['status'];
        } else {
            $warnings[] = supplierAvailabilityWarning('availability_unmapped', 'Значение наличия не сопоставлено для этого профиля');
        }
    }
    $arrival = supplierAvailabilityParseDate($rawArrival, supplierAvailabilityValidateDateFormat($profile['arrival_date_format'] ?? null), $today);
    $stock = supplierAvailabilityParseStock($rawStock);
    array_push($warnings, ...$arrival['warnings'], ...$stock['warnings']);
    $stockValue = $stock['value'];
    if (($status === 'out_of_stock' && is_int($stockValue) && $stockValue > 0) || ($status === 'in_stock' && $stockValue === 0)) {
        $status = 'unknown';
        $stockValue = null;
        $warnings[] = supplierAvailabilityWarning('status_stock_conflict', 'Статус наличия конфликтует с количеством; canonical значения сброшены в unknown');
    }
    if ($status === 'expected' && $arrival['value'] === null) {
        $warnings[] = supplierAvailabilityWarning('expected_without_date', 'Ожидаемое поступление указано без корректной даты');
    }
    return [
        'status' => $status,
        'stock_quantity' => $stockValue,
        'expected_arrival_at' => $arrival['value'],
        'warnings' => $warnings,
        'raw_availability' => $rawAvailability,
        'raw_arrival' => $rawArrival,
        'raw_stock' => $rawStock
    ];
}
