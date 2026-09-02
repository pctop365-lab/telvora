<?php

declare(strict_types=1);

if (!defined('TELVORA_MANAGER_REQUEST')) {
    http_response_code(404);
    exit;
}

final class SupplierImportPreviewException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 400
    ) {
        parent::__construct($message);
    }
}

const SUPPLIER_PREVIEW_MAX_FILE_BYTES = 2 * 1024 * 1024;
const SUPPLIER_PREVIEW_MAX_ZIP_ENTRIES = 2000;
const SUPPLIER_PREVIEW_MAX_ZIP_UNCOMPRESSED_BYTES = 32 * 1024 * 1024;
const SUPPLIER_PREVIEW_MAX_SOURCE_ROWS = 50000;
const SUPPLIER_PREVIEW_MAX_COLUMNS = 200;
const SUPPLIER_PREVIEW_MAX_CELL_CHARACTERS = 10000;
const SUPPLIER_PREVIEW_MAX_ROWS = 100;

function supplierPreviewCanonicalFields(): array
{
    return [
        'supplier_sku',
        'product_name',
        'purchase_price',
        'currency_code',
        'availability',
        'arrival_info',
        'model',
        'assembly_country',
        'market_region',
        'certification_supply_type'
    ];
}

function supplierPreviewFail(string $message, int $status = 400): never
{
    throw new SupplierImportPreviewException($message, $status);
}

function supplierPreviewStringLength(string $value): int
{
    return mb_strlen($value, 'UTF-8');
}

function supplierPreviewSafeFilename(string $filename): string
{
    $filename = basename(str_replace('\\', '/', $filename));
    $filename = preg_replace('/[\x00-\x1F\x7F]/u', '', $filename);

    if (!is_string($filename) || $filename === '') {
        return 'price-list';
    }

    if (supplierPreviewStringLength($filename) > 180) {
        $filename = mb_substr($filename, 0, 180, 'UTF-8');
    }

    return $filename;
}

function supplierPreviewValidateUpload(array $files): array
{
    if (count($files) !== 1 || !array_key_exists('file', $files)) {
        supplierPreviewFail('Необходимо загрузить ровно один файл');
    }

    $file = $files['file'];
    if (!is_array($file) || is_array($file['error'] ?? null)) {
        supplierPreviewFail('Не удалось обработать загруженный файл');
    }

    $uploadError = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if (in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        supplierPreviewFail('Файл слишком большой. Максимальный размер — 2 МБ.', 413);
    }
    if ($uploadError !== UPLOAD_ERR_OK) {
        supplierPreviewFail('Не удалось обработать загруженный файл');
    }

    $reportedSize = $file['size'] ?? null;
    if (!is_int($reportedSize) || $reportedSize <= 0) {
        supplierPreviewFail('Загруженный файл пуст');
    }
    if ($reportedSize > SUPPLIER_PREVIEW_MAX_FILE_BYTES) {
        supplierPreviewFail('Файл слишком большой. Максимальный размер — 2 МБ.', 413);
    }

    $temporaryPath = $file['tmp_name'] ?? null;
    if (!is_string($temporaryPath) || !is_uploaded_file($temporaryPath)) {
        supplierPreviewFail('Не удалось проверить загруженный файл');
    }

    $actualSize = filesize($temporaryPath);
    if ($actualSize === false || $actualSize <= 0) {
        supplierPreviewFail('Загруженный файл пуст');
    }
    if ($actualSize > SUPPLIER_PREVIEW_MAX_FILE_BYTES) {
        supplierPreviewFail('Файл слишком большой. Максимальный размер — 2 МБ.', 413);
    }

    $originalName = $file['name'] ?? '';
    if (!is_string($originalName)) {
        $originalName = '';
    }
    $safeFilename = supplierPreviewSafeFilename($originalName);
    $extension = strtolower(pathinfo($safeFilename, PATHINFO_EXTENSION));
    if (!in_array($extension, ['csv', 'xls', 'xlsx'], true)) {
        supplierPreviewFail('Поддерживаются только файлы CSV, XLS и XLSX');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($temporaryPath);
    if (!is_string($mime) || $mime === '') {
        supplierPreviewFail('Не удалось определить формат файла');
    }

    $allowedMimes = [
        'csv' => ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'],
        'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/octet-stream'],
        'xlsx' => [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
            'application/x-zip-compressed'
        ]
    ];
    if (!in_array($mime, $allowedMimes[$extension], true)) {
        supplierPreviewFail('Содержимое файла не соответствует его формату');
    }

    $handle = fopen($temporaryPath, 'rb');
    if ($handle === false) {
        supplierPreviewFail('Не удалось прочитать загруженный файл');
    }
    $signature = fread($handle, 8);
    fclose($handle);

    if ($extension === 'xls' && $signature !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
        supplierPreviewFail('Содержимое файла не соответствует формату XLS');
    }
    if (
        $extension === 'xlsx' &&
        (!is_string($signature) || !str_starts_with($signature, "PK"))
    ) {
        supplierPreviewFail('Содержимое файла не соответствует формату XLSX');
    }
    if ($extension === 'csv' && is_string($signature) && str_contains($signature, "\0")) {
        supplierPreviewFail('CSV должен быть текстовым файлом UTF-8');
    }

    return [
        'path' => $temporaryPath,
        'original_filename' => $safeFilename,
        'extension' => $extension,
        'mime' => $mime
    ];
}

function supplierPreviewValidateXlsxArchive(string $path): void
{
    $archive = new ZipArchive();
    if ($archive->open($path, ZipArchive::RDONLY) !== true) {
        supplierPreviewFail('Не удалось прочитать XLSX-файл');
    }

    try {
        if ($archive->numFiles <= 0 || $archive->numFiles > SUPPLIER_PREVIEW_MAX_ZIP_ENTRIES) {
            supplierPreviewFail('Структура XLSX-файла превышает допустимые ограничения');
        }

        $uncompressedBytes = 0;
        for ($index = 0; $index < $archive->numFiles; $index++) {
            $entry = $archive->statIndex($index, ZipArchive::FL_UNCHANGED);
            if (!is_array($entry)) {
                supplierPreviewFail('Не удалось проверить структуру XLSX-файла');
            }

            $entryName = $entry['name'] ?? '';
            $entrySize = $entry['size'] ?? null;
            if (!is_string($entryName) || !is_int($entrySize) || $entrySize < 0) {
                supplierPreviewFail('Не удалось проверить структуру XLSX-файла');
            }
            if (
                str_contains($entryName, "\0") ||
                str_starts_with($entryName, '/') ||
                preg_match('/\A[A-Za-z]:/', $entryName) === 1 ||
                in_array('..', explode('/', str_replace('\\', '/', $entryName)), true)
            ) {
                supplierPreviewFail('XLSX-файл содержит небезопасную структуру');
            }
            if (($entry['encryption_method'] ?? 0) !== 0) {
                supplierPreviewFail('Зашифрованные XLSX-файлы не поддерживаются');
            }

            $uncompressedBytes += $entrySize;
            if ($uncompressedBytes > SUPPLIER_PREVIEW_MAX_ZIP_UNCOMPRESSED_BYTES) {
                supplierPreviewFail('Структура XLSX-файла превышает допустимые ограничения');
            }
        }
    } finally {
        $archive->close();
    }
}

function supplierPreviewColumnIndex(string $column): int
{
    $column = strtoupper(trim($column));
    if (preg_match('/\A[A-Z]+\z/D', $column) !== 1) {
        supplierPreviewFail('Профиль содержит некорректную карту колонок');
    }

    $index = 0;
    for ($position = 0, $length = strlen($column); $position < $length; $position++) {
        $index = ($index * 26) + (ord($column[$position]) - 64);
        if ($index > SUPPLIER_PREVIEW_MAX_COLUMNS) {
            supplierPreviewFail('Карта колонок выходит за допустимый предел');
        }
    }

    return $index;
}

function supplierPreviewValidateProfile(array $profile): array
{
    $mapping = json_decode((string)($profile['column_mapping'] ?? ''), true);
    $options = json_decode((string)($profile['parser_options'] ?? ''), true);
    if (!is_array($mapping) || !is_array($options)) {
        supplierPreviewFail('Профиль импорта содержит некорректные настройки');
    }

    $allowedFields = supplierPreviewCanonicalFields();
    $validatedMapping = [];
    foreach ($mapping as $field => $column) {
        if (
            !is_string($field) ||
            !in_array($field, $allowedFields, true) ||
            !is_string($column)
        ) {
            supplierPreviewFail('Профиль содержит некорректную карту колонок');
        }
        $column = strtoupper(trim($column));
        $validatedMapping[$field] = [
            'column' => $column,
            'index' => supplierPreviewColumnIndex($column)
        ];
    }
    if ($validatedMapping === []) {
        supplierPreviewFail('В профиле не настроена карта колонок');
    }

    $allowedOptionKeys = [
        'trim_values', 'skip_empty_rows', 'decimal_separator', 'default_currency_code'
    ];
    foreach (array_keys($options) as $key) {
        if (!is_string($key) || !in_array($key, $allowedOptionKeys, true)) {
            supplierPreviewFail('Профиль импорта содержит некорректные настройки');
        }
    }

    $trimValues = $options['trim_values'] ?? true;
    $skipEmptyRows = $options['skip_empty_rows'] ?? true;
    $decimalSeparator = $options['decimal_separator'] ?? '.';
    $defaultCurrency = $options['default_currency_code'] ?? null;
    if (!is_bool($trimValues) || !is_bool($skipEmptyRows)) {
        supplierPreviewFail('Профиль импорта содержит некорректные настройки');
    }
    if (!is_string($decimalSeparator) || !in_array($decimalSeparator, ['.', ','], true)) {
        supplierPreviewFail('Профиль импорта содержит некорректные настройки');
    }
    if ($defaultCurrency !== null) {
        if (!is_string($defaultCurrency)) {
            supplierPreviewFail('Профиль импорта содержит некорректные настройки');
        }
        $defaultCurrency = strtoupper(trim($defaultCurrency));
        if (preg_match('/\A[A-Z]{3}\z/D', $defaultCurrency) !== 1) {
            supplierPreviewFail('Профиль импорта содержит некорректные настройки');
        }
    }

    $headerRow = filter_var($profile['header_row_number'] ?? null, FILTER_VALIDATE_INT);
    if ($headerRow === false || $headerRow < 0 || $headerRow > SUPPLIER_PREVIEW_MAX_SOURCE_ROWS) {
        supplierPreviewFail('Строка заголовков выходит за допустимый предел');
    }

    $sheetName = $profile['sheet_name'] ?? null;
    if ($sheetName !== null && !is_string($sheetName)) {
        supplierPreviewFail('Профиль импорта содержит некорректное имя листа');
    }
    $sheetName = is_string($sheetName) ? trim($sheetName) : '';
    $sheetControlMatch = preg_match('/[\x00-\x1F\x7F]/u', $sheetName);
    if (
        supplierPreviewStringLength($sheetName) > 255 ||
        str_contains($sheetName, '/') ||
        str_contains($sheetName, '\\') ||
        $sheetControlMatch !== 0
    ) {
        supplierPreviewFail('Профиль импорта содержит некорректное имя листа');
    }

    return [
        'mapping' => $validatedMapping,
        'mapping_response' => array_map(
            static fn (array $item): string => $item['column'],
            $validatedMapping
        ),
        'options' => [
            'trim_values' => $trimValues,
            'skip_empty_rows' => $skipEmptyRows,
            'decimal_separator' => $decimalSeparator,
            'default_currency_code' => $defaultCurrency
        ],
        'header_row_number' => $headerRow,
        'sheet_name' => $sheetName
    ];
}

function supplierPreviewCleanText(string $value, bool $trim): string
{
    $cleaned = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value);
    if (!is_string($cleaned)) {
        supplierPreviewFail('Файл содержит текст не в кодировке UTF-8');
    }
    return $trim ? trim($cleaned) : $cleaned;
}

function supplierPreviewScalarToString(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if (is_int($value) || is_float($value) || is_string($value)) {
        return (string)$value;
    }
    if ($value instanceof PhpOffice\PhpSpreadsheet\RichText\RichText) {
        return $value->getPlainText();
    }

    return '';
}

function supplierPreviewFormatSpreadsheetValue(
    PhpOffice\PhpSpreadsheet\Cell\Cell $cell,
    mixed $value,
    bool $allowNumericString,
    array &$warnings,
    string $field
): string {
    if ($value === null) {
        return '';
    }

    $isNumericValue = is_int($value) || is_float($value) ||
        ($allowNumericString && is_string($value) && is_numeric($value));
    if (!$isNumericValue) {
        return supplierPreviewScalarToString($value);
    }

    $currentCalendar = PhpOffice\PhpSpreadsheet\Shared\Date::getExcelCalendar();
    PhpOffice\PhpSpreadsheet\Shared\Date::setExcelCalendar(
        $cell->getWorksheet()->getParent()?->getExcelCalendar()
    );

    try {
        $formatCode = (string)$cell->getStyle()
            ->getNumberFormat()
            ->getFormatCode(true);

        return PhpOffice\PhpSpreadsheet\Style\NumberFormat::toFormattedString(
            $value,
            $formatCode
        );
    } catch (Throwable $error) {
        $warnings[] = $field . ': не удалось применить формат ячейки';
        return supplierPreviewScalarToString($value);
    } finally {
        PhpOffice\PhpSpreadsheet\Shared\Date::setExcelCalendar($currentCalendar);
    }
}

function supplierPreviewNormalizePrice(string $value, string $decimalSeparator, array &$errors): ?string
{
    if ($value === '') {
        return null;
    }

    $compact = preg_replace('/[\x{0020}\x{00A0}\x{2007}\x{202F}]/u', '', $value);
    if (!is_string($compact)) {
        $errors[] = 'Некорректная цена закупки';
        return null;
    }

    $quotedSeparator = preg_quote($decimalSeparator, '/');
    if (preg_match('/\A\+?\d+(?:' . $quotedSeparator . '\d+)?\z/D', $compact) !== 1) {
        $errors[] = str_starts_with($compact, '-')
            ? 'Цена закупки не может быть отрицательной'
            : 'Некорректная цена закупки';
        return null;
    }

    $canonical = $decimalSeparator === ',' ? str_replace(',', '.', $compact) : $compact;
    $canonical = ltrim($canonical, '+');
    [$integer, $fraction] = array_pad(explode('.', $canonical, 2), 2, null);
    $integer = ltrim($integer, '0');
    $integer = $integer === '' ? '0' : $integer;
    $canonical = $fraction === null ? $integer : $integer . '.' . $fraction;

    $numeric = (float)$canonical;
    if (!is_finite($numeric)) {
        $errors[] = 'Цена закупки выходит за допустимый диапазон';
        return null;
    }

    return $canonical;
}

function supplierPreviewBuildRow(
    int $sourceRowNumber,
    array $sourceValues,
    array $profile,
    array $cellWarnings = []
): array {
    $values = [];
    $errors = [];
    $warnings = $cellWarnings;
    $oversizedFields = [];

    foreach ($profile['mapping'] as $field => $mapping) {
        $sourceValue = array_key_exists($field, $sourceValues)
            ? $sourceValues[$field]
            : ($sourceValues[$mapping['index']] ?? null);
        $value = supplierPreviewScalarToString($sourceValue);
        if (!mb_check_encoding($value, 'UTF-8')) {
            $errors[] = $field . ': текст не в кодировке UTF-8';
            $value = '';
        }
        if (supplierPreviewStringLength($value) > SUPPLIER_PREVIEW_MAX_CELL_CHARACTERS) {
            $errors[] = $field . ': значение превышает 10000 символов';
            $oversizedFields[$field] = true;
            $value = mb_substr($value, 0, SUPPLIER_PREVIEW_MAX_CELL_CHARACTERS, 'UTF-8');
        }
        $values[$field] = supplierPreviewCleanText(
            $value,
            $profile['options']['trim_values']
        );
    }

    $isEmpty = true;
    foreach ($values as $value) {
        if ($value !== '') {
            $isEmpty = false;
            break;
        }
    }

    $normalized = $values;
    if (array_key_exists('purchase_price', $values)) {
        $normalized['purchase_price'] = isset($oversizedFields['purchase_price'])
            ? null
            : supplierPreviewNormalizePrice(
                $values['purchase_price'],
                $profile['options']['decimal_separator'],
                $errors
            );
    }

    $currency = $values['currency_code'] ?? '';
    if ($currency === '') {
        $currency = $profile['options']['default_currency_code'] ?? '';
    }
    if (array_key_exists('currency_code', $values) || $currency !== '') {
        $currency = strtoupper(trim((string)$currency));
        if ($currency !== '' && preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1) {
            $errors[] = 'Некорректный код валюты';
            $normalized['currency_code'] = null;
        } else {
            $normalized['currency_code'] = $currency === '' ? null : $currency;
        }
    }

    return [
        'source_row_number' => $sourceRowNumber,
        'values' => $values,
        'normalized' => $normalized,
        'errors' => array_values(array_unique($errors)),
        'warnings' => array_values(array_unique($warnings)),
        '_empty' => $isEmpty
    ];
}

function supplierPreviewAccumulator(
    ?callable $rowConsumer = null,
    int $capturedRowLimit = SUPPLIER_PREVIEW_MAX_ROWS
): array
{
    return [
        'rows_scanned' => 0,
        'rows_skipped' => 0,
        'rows_with_errors' => 0,
        'eligible_rows' => 0,
        'rows' => [],
        '_row_consumer' => $rowConsumer,
        '_captured_row_limit' => $capturedRowLimit
    ];
}

function supplierPreviewAccumulateRow(array &$result, array $row, bool $skipEmptyRows): void
{
    $result['rows_scanned']++;
    if (
        $skipEmptyRows &&
        $row['_empty'] &&
        $row['errors'] === [] &&
        $row['warnings'] === []
    ) {
        $result['rows_skipped']++;
        return;
    }
    unset($row['_empty']);
    $result['eligible_rows']++;
    if ($row['errors'] !== []) {
        $result['rows_with_errors']++;
    }
    if (is_callable($result['_row_consumer'])) {
        ($result['_row_consumer'])($row);
    }
    if (count($result['rows']) < $result['_captured_row_limit']) {
        $result['rows'][] = $row;
    }
}

function supplierPreviewFinalizeAccumulator(array &$result): void
{
    $result['preview_truncated'] =
        $result['eligible_rows'] > $result['_captured_row_limit'];
    unset(
        $result['eligible_rows'],
        $result['_row_consumer'],
        $result['_captured_row_limit']
    );
}

function supplierPreviewDetectCsvDelimiter(string $path): string
{
    $candidates = [',', ';', "\t"];
    $scores = [];

    foreach ($candidates as $delimiter) {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            supplierPreviewFail('Не удалось прочитать CSV-файл');
        }
        $counts = [];
        try {
            while (count($counts) < 20 && ($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                if ($row === [null] || $row === []) {
                    continue;
                }
                $counts[] = count($row);
            }
        } finally {
            fclose($handle);
        }

        $frequencies = array_count_values($counts);
        arsort($frequencies);
        $modeColumns = (int)(array_key_first($frequencies) ?? 0);
        $consistentRows = (int)($frequencies[$modeColumns] ?? 0);
        $scores[$delimiter] = $modeColumns > 1 ? ($consistentRows * 1000) + $modeColumns : 0;
    }

    arsort($scores);
    $bestDelimiter = array_key_first($scores);
    $bestScore = (int)($scores[$bestDelimiter] ?? 0);
    $scoreValues = array_values($scores);
    if ($bestScore === 0 || ($scoreValues[1] ?? -1) === $bestScore) {
        supplierPreviewFail('Не удалось надёжно определить разделитель CSV');
    }

    return (string)$bestDelimiter;
}

function supplierPreviewParseCsv(
    string $path,
    array $profile,
    ?callable $rowConsumer = null,
    int $capturedRowLimit = SUPPLIER_PREVIEW_MAX_ROWS
): array
{
    $delimiter = supplierPreviewDetectCsvDelimiter($path);
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        supplierPreviewFail('Не удалось прочитать CSV-файл');
    }

    $result = supplierPreviewAccumulator($rowConsumer, $capturedRowLimit);
    $headers = [];
    $recordNumber = 0;
    try {
        while (($sourceRow = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            $recordNumber++;
            if ($recordNumber > SUPPLIER_PREVIEW_MAX_SOURCE_ROWS) {
                supplierPreviewFail('CSV содержит больше 50000 строк');
            }
            if (count($sourceRow) > SUPPLIER_PREVIEW_MAX_COLUMNS) {
                supplierPreviewFail('CSV содержит больше 200 колонок');
            }

            if ($recordNumber === 1 && isset($sourceRow[0]) && is_string($sourceRow[0])) {
                $sourceRow[0] = preg_replace('/\A\xEF\xBB\xBF/', '', $sourceRow[0]);
            }
            $indexedValues = [];
            foreach ($sourceRow as $zeroBasedIndex => $value) {
                if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
                    supplierPreviewFail('CSV должен быть текстовым файлом UTF-8');
                }
                $indexedValues[$zeroBasedIndex + 1] = $value;
            }

            if ($profile['header_row_number'] > 0 && $recordNumber === $profile['header_row_number']) {
                foreach ($profile['mapping'] as $field => $mapping) {
                    $headerText = (string)($indexedValues[$mapping['index']] ?? '');
                    if (supplierPreviewStringLength($headerText) > SUPPLIER_PREVIEW_MAX_CELL_CHARACTERS) {
                        supplierPreviewFail('Строка заголовков содержит слишком длинное значение');
                    }
                    $headers[$field] = supplierPreviewCleanText($headerText, true);
                }
                continue;
            }
            if ($profile['header_row_number'] > 0 && $recordNumber < $profile['header_row_number']) {
                continue;
            }

            $row = supplierPreviewBuildRow($recordNumber, $indexedValues, $profile);
            supplierPreviewAccumulateRow($result, $row, $profile['options']['skip_empty_rows']);
        }
    } finally {
        fclose($handle);
    }

    if ($recordNumber === 0) {
        supplierPreviewFail('CSV-файл не содержит строк');
    }
    if ($profile['header_row_number'] > $recordNumber) {
        supplierPreviewFail('Указанная строка заголовков отсутствует в файле');
    }

    $result['detected_headers'] = $headers;
    supplierPreviewFinalizeAccumulator($result);
    $result['csv_delimiter'] = $delimiter === "\t" ? 'tab' : $delimiter;
    return $result;
}

function supplierPreviewLoadSpreadsheet(
    string $path,
    string $extension,
    array $profile,
    ?callable $rowConsumer = null,
    int $capturedRowLimit = SUPPLIER_PREVIEW_MAX_ROWS
): array
{
    if ($extension === 'xlsx') {
        supplierPreviewValidateXlsxArchive($path);
    }

    $expectedType = $extension === 'xlsx' ? 'Xlsx' : 'Xls';
    try {
        $identifiedType = PhpOffice\PhpSpreadsheet\IOFactory::identify(
            $path,
            [$expectedType]
        );
    } catch (Throwable $error) {
        throw new SupplierImportPreviewException('Не удалось определить формат таблицы');
    }
    if ($identifiedType !== $expectedType) {
        supplierPreviewFail('Содержимое файла не соответствует его формату');
    }

    try {
        $reader = PhpOffice\PhpSpreadsheet\IOFactory::createReader($identifiedType);
        $reader->setReadDataOnly(false);
        $reader->setReadEmptyCells(false);
        $reader->setIncludeCharts(false);
        $reader->setAllowExternalImages(false);
        $worksheetNames = $reader->listWorksheetNames($path);
        if ($worksheetNames === []) {
            supplierPreviewFail('Таблица не содержит листов');
        }

        $requestedSheet = $profile['sheet_name'];
        $sheetName = $requestedSheet === '' ? (string)$worksheetNames[0] : $requestedSheet;
        if (!in_array($sheetName, $worksheetNames, true)) {
            supplierPreviewFail('Указанный в профиле лист отсутствует в файле');
        }

        $worksheetInfo = $reader->listWorksheetInfo($path);
        $selectedSheetInfo = null;
        foreach ($worksheetInfo as $sheetInfo) {
            if (($sheetInfo['worksheetName'] ?? null) === $sheetName) {
                $selectedSheetInfo = $sheetInfo;
                break;
            }
        }
        if (!is_array($selectedSheetInfo)) {
            supplierPreviewFail('Не удалось проверить размер выбранного листа');
        }
        if ((int)($selectedSheetInfo['totalRows'] ?? 0) > SUPPLIER_PREVIEW_MAX_SOURCE_ROWS) {
            supplierPreviewFail('Таблица содержит больше 50000 строк');
        }
        if ((int)($selectedSheetInfo['totalColumns'] ?? 0) > SUPPLIER_PREVIEW_MAX_COLUMNS) {
            supplierPreviewFail('Таблица содержит больше 200 колонок');
        }

        $reader->setLoadSheetsOnly([$sheetName]);
        $reader->setReadFilter(new class implements PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
            public function readCell(
                string $columnAddress,
                int $row,
                string $worksheetName = ''
            ): bool {
                if ($row > SUPPLIER_PREVIEW_MAX_SOURCE_ROWS) {
                    return false;
                }
                return PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString(
                    $columnAddress
                ) <= SUPPLIER_PREVIEW_MAX_COLUMNS;
            }
        });
        $spreadsheet = $reader->load($path);
    } catch (SupplierImportPreviewException $error) {
        throw $error;
    } catch (Throwable $error) {
        error_log('supplier import preview spreadsheet load failed: ' . $error->getMessage());
        supplierPreviewFail('Не удалось прочитать таблицу');
    }

    try {
        $worksheet = $spreadsheet->getSheetByName($sheetName);
        if ($worksheet === null) {
            supplierPreviewFail('Указанный в профиле лист отсутствует в файле');
        }

        $highestRow = $worksheet->getHighestDataRow();
        $highestColumnIndex = PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString(
            $worksheet->getHighestDataColumn()
        );
        if ($highestRow > SUPPLIER_PREVIEW_MAX_SOURCE_ROWS) {
            supplierPreviewFail('Таблица содержит больше 50000 строк');
        }
        if ($highestColumnIndex > SUPPLIER_PREVIEW_MAX_COLUMNS) {
            supplierPreviewFail('Таблица содержит больше 200 колонок');
        }
        if ($highestRow < 1) {
            supplierPreviewFail('Таблица не содержит строк');
        }
        if ($profile['header_row_number'] > $highestRow) {
            supplierPreviewFail('Указанная строка заголовков отсутствует в файле');
        }

        $headers = [];
        if ($profile['header_row_number'] > 0) {
            foreach ($profile['mapping'] as $field => $mapping) {
                $cellAddress = PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
                    $mapping['index']
                ) . $profile['header_row_number'];
                $cell = $worksheet->getCell($cellAddress);
                if ($cell->getDataType() === PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_FORMULA) {
                    supplierPreviewFail('Формулы в строке заголовков не поддерживаются');
                }
                $headerWarnings = [];
                $headerText = supplierPreviewFormatSpreadsheetValue(
                    $cell,
                    $cell->getValue(),
                    false,
                    $headerWarnings,
                    $field
                );
                if ($headerWarnings !== []) {
                    supplierPreviewFail('Не удалось применить формат строки заголовков');
                }
                if (supplierPreviewStringLength($headerText) > SUPPLIER_PREVIEW_MAX_CELL_CHARACTERS) {
                    supplierPreviewFail('Строка заголовков содержит слишком длинное значение');
                }
                $headers[$field] = supplierPreviewCleanText($headerText, true);
            }
        }

        $result = supplierPreviewAccumulator($rowConsumer, $capturedRowLimit);
        $firstDataRow = $profile['header_row_number'] > 0
            ? $profile['header_row_number'] + 1
            : 1;
        for ($rowNumber = $firstDataRow; $rowNumber <= $highestRow; $rowNumber++) {
            $sourceValues = [];
            $warnings = [];
            foreach ($profile['mapping'] as $field => $mapping) {
                $cellAddress = PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
                    $mapping['index']
                ) . $rowNumber;
                $cell = $worksheet->getCell($cellAddress);
                if ($cell->getDataType() === PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_FORMULA) {
                    $value = $cell->getOldCalculatedValue();
                    if ($value === null) {
                        $warnings[] = $field . ': формула не вычислялась, сохранённое значение отсутствует';
                    } else {
                        $warnings[] = $field . ': использовано сохранённое значение формулы';
                        $value = supplierPreviewFormatSpreadsheetValue(
                            $cell,
                            $value,
                            true,
                            $warnings,
                            $field
                        );
                    }
                } else {
                    $value = supplierPreviewFormatSpreadsheetValue(
                        $cell,
                        $cell->getValue(),
                        false,
                        $warnings,
                        $field
                    );
                }
                $sourceValues[$field] = $value;
            }

            $row = supplierPreviewBuildRow($rowNumber, $sourceValues, $profile, $warnings);
            supplierPreviewAccumulateRow($result, $row, $profile['options']['skip_empty_rows']);
        }

        $result['detected_headers'] = $headers;
        supplierPreviewFinalizeAccumulator($result);
        $result['sheet_name'] = $sheetName;
        return $result;
    } finally {
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
    }
}

function supplierPreviewParse(
    array $upload,
    array $profile,
    ?callable $rowConsumer = null,
    int $capturedRowLimit = SUPPLIER_PREVIEW_MAX_ROWS
): array
{
    if ($upload['extension'] === 'csv') {
        $result = supplierPreviewParseCsv(
            $upload['path'],
            $profile,
            $rowConsumer,
            $capturedRowLimit
        );
        $result['sheet_name'] = 'CSV';
        return $result;
    }

    return supplierPreviewLoadSpreadsheet(
        $upload['path'],
        $upload['extension'],
        $profile,
        $rowConsumer,
        $capturedRowLimit
    );
}
