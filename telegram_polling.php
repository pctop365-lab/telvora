<?php

function telvoraSecretsFile(): string
{
    return dirname(__DIR__, 2) .
        '/telvora_runtime/telvora_secrets.php';
}

function loadTelvoraSecrets(): array
{
    static $secrets = null;

    if (is_array($secrets)) {
        return $secrets;
    }

    $file = telvoraSecretsFile();

    if (!is_file($file) || !is_readable($file)) {
        throw new RuntimeException(
            'TELVORA private configuration is unavailable.'
        );
    }

    try {
        $loaded = @require $file;
    } catch (Throwable $e) {
        throw new RuntimeException(
            'TELVORA private configuration is unavailable.'
        );
    }

    if (!is_array($loaded)) {
        throw new RuntimeException(
            'TELVORA private configuration is unavailable.'
        );
    }

    $secrets = $loaded;

    return $secrets;
}

function requireTelvoraSecret(string $key): string
{
    $secrets = loadTelvoraSecrets();

    if (
        !array_key_exists($key, $secrets) ||
        !is_string($secrets[$key]) ||
        trim($secrets[$key]) === ''
    ) {
        throw new RuntimeException(
            'TELVORA private configuration is unavailable.'
        );
    }

    return $secrets[$key];
}

// ============================================================
// TELVORA — Telegram Polling
// Получает сообщения Telegram через getUpdates
// ============================================================

function telegramRequest(string $method, array $data = []): ?array
{
    $telegramBotToken =
        requireTelvoraSecret('telegram_bot_token');

    $url = 'https://api.telegram.org/bot' . $telegramBotToken . '/' . $method;

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 10,
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        curl_close($ch);
        return null;
    }

    curl_close($ch);

    $decoded = json_decode($response, true);

    return is_array($decoded) ? $decoded : null;
}

function telegramSecurityFile(): string
{
    return dirname(__DIR__, 2) .
        '/telvora_runtime/telegram_security.json';
}

function telegramOffsetFile(): string
{
    return dirname(__DIR__, 2) .
        '/telvora_runtime/telegram_offset.txt';
}

function normalizeTelegramUserId($value): ?string
{
    if (is_int($value)) {
        if ($value <= 0) {
            return null;
        }

        $value = (string)$value;
    } elseif (is_float($value)) {
        if (
            !is_finite($value) ||
            $value <= 0 ||
            floor($value) !== $value ||
            $value > 9007199254740991
        ) {
            return null;
        }

        $value = sprintf('%.0f', $value);
    } elseif (!is_string($value)) {
        return null;
    }

    if (!preg_match('/^[1-9][0-9]*$/D', $value)) {
        return null;
    }

    return $value;
}

function loadAllowedTelegramUserIds(): array
{
    $raw = @file_get_contents(telegramSecurityFile());

    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $config = json_decode(
        $raw,
        true,
        512,
        JSON_BIGINT_AS_STRING
    );

    if (
        !is_array($config) ||
        !array_key_exists('allowed_user_ids', $config)
    ) {
        return [];
    }

    $configuredIds = $config['allowed_user_ids'];

    if (!is_array($configuredIds)) {
        $configuredIds = [$configuredIds];
    }

    $allowedIds = [];

    foreach ($configuredIds as $configuredId) {
        $normalizedId = normalizeTelegramUserId($configuredId);

        if ($normalizedId !== null) {
            $allowedIds[] = $normalizedId;
        }
    }

    return array_values(array_unique($allowedIds));
}

function isTelegramUserAllowed($userId): bool
{
    $normalizedId = normalizeTelegramUserId($userId);

    if ($normalizedId === null) {
        return false;
    }

    return in_array(
        $normalizedId,
        loadAllowedTelegramUserIds(),
        true
    );
}


// ============================================================
// DATABASE
// ============================================================

function getDatabase(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dbHost = requireTelvoraSecret('db_host');
    $dbName = requireTelvoraSecret('db_name');
    $dbUser = requireTelvoraSecret('db_user');
    $dbPassword = requireTelvoraSecret('db_password');

    try {
        $pdo = new PDO(
            'mysql:host=' . $dbHost .
                ';dbname=' . $dbName .
                ';charset=utf8mb4',
            $dbUser,
            $dbPassword,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        );
    } catch (Throwable $e) {
        throw new RuntimeException(
            'Database connection is unavailable.'
        );
    }

    return $pdo;
}
// ============================================================
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
// ============================================================

function h($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function money($value): string

{
    return number_format(
        (float)$value,
        0,
        ',',
        ' '
    ) . ' ₽';
}

function editStateFile(): string
{
    return dirname(__DIR__, 2) . '/telvora_runtime/telegram_edit_state.json';
}

function restoreLockedEditState($handle, string $originalRaw): void
{
    rewind($handle);

    if (!ftruncate($handle, 0)) {
        return;
    }

    if (
        $originalRaw !== '' &&
        !writeAll($handle, $originalRaw)
    ) {
        return;
    }

    fflush($handle);
}

function writeLockedEditState(
    $handle,
    array $data,
    string $originalRaw
): bool {
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
    );

    if (!is_string($json)) {
        return false;
    }

    rewind($handle);

    if (!ftruncate($handle, 0)) {
        return false;
    }

    if (
        !writeAll($handle, $json) ||
        !fflush($handle)
    ) {
        restoreLockedEditState($handle, $originalRaw);
        return false;
    }

    return true;
}

function loadEditState(int $chatId): ?array
{
    $file = editStateFile();

    if (!file_exists($file)) {
        return null;
    }

    $handle = @fopen($file, 'r+');

    if ($handle === false) {
        return null;
    }

    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        return null;
    }

    try {
        rewind($handle);
        $raw = stream_get_contents($handle);

        if (!is_string($raw)) {
            return null;
        }

        $originalRaw = $raw;

        if (trim($raw) === '') {
            $data = [];
        } else {
            $decoded = json_decode($raw, true);

            if (!is_array($decoded)) {
                return null;
            }

            $data = $decoded;
        }

        $key = (string)$chatId;

        if (!array_key_exists($key, $data)) {
            return null;
        }

        $state = $data[$key];

        if (!is_array($state)) {
            unset($data[$key]);
            writeLockedEditState(
                $handle,
                $data,
                $originalRaw
            );
            return null;
        }

        return $state;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function saveEditState(int $chatId, array $state): void
{
    $file = editStateFile();

    $handle = @fopen($file, 'c+');

    if ($handle === false) {
        return;
    }

    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        return;
    }

    try {
        rewind($handle);
        $raw = stream_get_contents($handle);

        if (!is_string($raw)) {
            return;
        }

        $originalRaw = $raw;

        if (trim($raw) === '') {
            $data = [];
        } else {
            $decoded = json_decode($raw, true);

            if (!is_array($decoded)) {
                return;
            }

            $data = $decoded;
        }

        $data[(string)$chatId] = $state;

        writeLockedEditState(
            $handle,
            $data,
            $originalRaw
        );
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function clearEditState(int $chatId): void
{
    $file = editStateFile();

    if (!file_exists($file)) {
        return;
    }

    $handle = @fopen($file, 'r+');

    if ($handle === false) {
        return;
    }

    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        return;
    }

    try {
        rewind($handle);
        $raw = stream_get_contents($handle);

        if (!is_string($raw) || trim($raw) === '') {
            return;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return;
        }

        $key = (string)$chatId;

        if (!array_key_exists($key, $decoded)) {
            return;
        }

        unset($decoded[$key]);

        writeLockedEditState(
            $handle,
            $decoded,
            $raw
        );
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

const DELETE_INFLIGHT_STALE_AFTER = 60;

function messageStateFile(): string
{
    return dirname(__DIR__, 2) . '/telvora_runtime/telegram_message_state.json';
}

function normalizeChatMessageState(array $state, int $chatId): array
{
    $chatState = $state[(string)$chatId] ?? [];
    return is_array($chatState) ? $chatState : [];
}

function normalizeMessageIds($value): array
{
    if (!is_array($value)) return [];
    $ids = [];
    foreach ($value as $messageId) {
        $messageId = (int)$messageId;
        if ($messageId > 0) $ids[] = $messageId;
    }
    return array_values(array_unique($ids));
}

function normalizeInflightDeletes($value): array
{
    if (!is_array($value)) return [];
    $inflight = [];
    foreach ($value as $messageId => $claim) {
        $messageId = (int)$messageId;
        if ($messageId <= 0 || !is_array($claim)) continue;
        $token = $claim['token'] ?? '';
        $claimedAt = (int)($claim['claimed_at'] ?? 0);
        if (!is_string($token) || $token === '' || $claimedAt <= 0) continue;
        $inflight[(string)$messageId] = [
            'token' => $token,
            'claimed_at' => $claimedAt
        ];
    }
    return $inflight;
}

function isMessageActiveInChatState(array $chatState, int $messageId): bool
{
    if ($messageId <= 0) return false;
    if ((int)($chatState['main_menu'] ?? 0) === $messageId) return true;
    if ((int)($chatState['order_card'] ?? 0) === $messageId) return true;

    if (in_array(
        $messageId,
        normalizeMessageIds($chatState['orders'] ?? []),
        true
    )) {
        return true;
    }

    return in_array(
        $messageId,
        normalizeMessageIds(
            $chatState['transient_messages'] ?? []
        ),
        true
    );
}

function loadMessageState(): array
{
    $file = messageStateFile();
    if (!file_exists($file)) return [];
    $handle = @fopen($file, 'rb');
    if ($handle === false) return [];
    if (!flock($handle, LOCK_SH)) {
        fclose($handle);
        return [];
    }
    try {
        $raw = stream_get_contents($handle);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
    $decoded = json_decode(is_string($raw) ? $raw : '', true);
    return is_array($decoded) ? $decoded : [];
}

function writeAll($handle, string $content): bool
{
    $length = strlen($content);
    $offset = 0;
    while ($offset < $length) {
        $written = fwrite($handle, substr($content, $offset));
        if ($written === false || $written === 0) return false;
        $offset += $written;
    }
    return true;
}

function restoreLockedMessageState($handle, string $originalRaw): void
{
    rewind($handle);
    if (!ftruncate($handle, 0)) return;
    if ($originalRaw !== '' && !writeAll($handle, $originalRaw)) return;
    fflush($handle);
}

function mutateMessageState(callable $mutator): bool
{
    $handle = @fopen(messageStateFile(), 'c+');
    if ($handle === false) return false;
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        return false;
    }
    $success = false;
    try {
        rewind($handle);
        $raw = stream_get_contents($handle);
        $originalRaw = is_string($raw) ? $raw : '';
        $decoded = json_decode($originalRaw, true);
        $state = is_array($decoded) ? $decoded : [];
        $mutator($state);
        $json = json_encode(
            $state,
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );
        if (!is_string($json)) return false;
        rewind($handle);
        if (!ftruncate($handle, 0)) return false;
        if (!writeAll($handle, $json) || !fflush($handle)) {
            restoreLockedMessageState($handle, $originalRaw);
            return false;
        }
        $success = true;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
    return $success;
}

function mutateChatMessageState(int $chatId, callable $mutator): bool
{
    return mutateMessageState(
        static function (array &$state) use ($chatId, $mutator): void {
            $key = (string)$chatId;
            $chatState = normalizeChatMessageState($state, $chatId);
            $mutator($chatState);
            if ($chatState === []) unset($state[$key]);
            else $state[$key] = $chatState;
        }
    );
}

function extractSentMessageId(?array $response): ?int
{
    if (
        !$response ||
        empty($response['ok']) ||
        !isset($response['result']) ||
        !is_array($response['result'])
    ) return null;
    $messageId = (int)($response['result']['message_id'] ?? 0);
    return $messageId > 0 ? $messageId : null;
}

function deleteSavedBotMessage(int $chatId, int $messageId): string
{
    if ($chatId === 0 || $messageId <= 0) return 'discard';
    try {
        $response = telegramRequest('deleteMessage', [
            'chat_id' => $chatId,
            'message_id' => $messageId
        ]);
    } catch (Throwable $e) {
        return 'retry';
    }
    if (!is_array($response)) return 'retry';
    if (!empty($response['ok'])) return 'complete';
    $errorCode = (int)($response['error_code'] ?? 0);
    $description = strtolower((string)($response['description'] ?? ''));
    if (
        str_contains($description, 'message to delete not found') ||
        str_contains($description, 'message not found')
    ) return 'complete';
    if (
        str_contains($description, "message can't be deleted") ||
        str_contains($description, 'message cant be deleted')
    ) return 'discard';
    if ($errorCode === 429 || $errorCode >= 500) return 'retry';
    return 'retry';
}

function recoverStaleInflightDeletes(int $chatId): void
{
    $now = time();
    mutateChatMessageState(
        $chatId,
        static function (array &$chatState) use ($now): void {
            $inflight = normalizeInflightDeletes(
                $chatState['inflight_delete'] ?? []
            );
            $pending = normalizeMessageIds(
                $chatState['pending_delete'] ?? []
            );
            foreach ($inflight as $messageId => $claim) {
                $messageId = (int)$messageId;
                if (
                    $now - (int)$claim['claimed_at'] <
                    DELETE_INFLIGHT_STALE_AFTER
                ) continue;
                unset($inflight[(string)$messageId]);
                if (!isMessageActiveInChatState($chatState, $messageId)) {
                    $pending[] = $messageId;
                }
            }
            if ($inflight === []) unset($chatState['inflight_delete']);
            else $chatState['inflight_delete'] = $inflight;
            if ($pending === []) unset($chatState['pending_delete']);
            else {
                $chatState['pending_delete'] =
                    array_values(array_unique($pending));
            }
        }
    );
}

function claimPendingDelete(int $chatId, int $messageId): ?string
{
    if ($messageId <= 0) return null;
    try {
        $claimToken = bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        return null;
    }
    if ($claimToken === '') return null;
    $claimed = false;
    $written = mutateChatMessageState(
        $chatId,
        static function (array &$chatState) use (
            $messageId,
            $claimToken,
            &$claimed
        ): void {
            $pending = normalizeMessageIds(
                $chatState['pending_delete'] ?? []
            );
            $inflight = normalizeInflightDeletes(
                $chatState['inflight_delete'] ?? []
            );
            if (
                !in_array($messageId, $pending, true) ||
                isset($inflight[(string)$messageId]) ||
                isMessageActiveInChatState($chatState, $messageId)
            ) return;
            $pending = array_values(array_filter(
                $pending,
                static fn(int $storedId): bool => $storedId !== $messageId
            ));
            $inflight[(string)$messageId] = [
                'token' => $claimToken,
                'claimed_at' => time()
            ];
            if ($pending === []) unset($chatState['pending_delete']);
            else $chatState['pending_delete'] = $pending;
            $chatState['inflight_delete'] = $inflight;
            $claimed = true;
        }
    );
    return $written && $claimed ? $claimToken : null;
}

function finishInflightDelete(
    int $chatId,
    int $messageId,
    string $claimToken,
    string $result
): void {
    if ($messageId <= 0 || $claimToken === '') return;
    mutateChatMessageState(
        $chatId,
        static function (array &$chatState) use (
            $messageId,
            $claimToken,
            $result
        ): void {
            $inflight = normalizeInflightDeletes(
                $chatState['inflight_delete'] ?? []
            );
            $currentClaim = $inflight[(string)$messageId] ?? null;
            if (
                !is_array($currentClaim) ||
                ($currentClaim['token'] ?? null) !== $claimToken
            ) return;
            unset($inflight[(string)$messageId]);
            if ($inflight === []) unset($chatState['inflight_delete']);
            else $chatState['inflight_delete'] = $inflight;
            if (
                $result === 'retry' &&
                !isMessageActiveInChatState($chatState, $messageId)
            ) {
                $pending = normalizeMessageIds(
                    $chatState['pending_delete'] ?? []
                );
                $pending[] = $messageId;
                $chatState['pending_delete'] =
                    array_values(array_unique($pending));
            }
        }
    );
}

function deletePendingBotMessages(int $chatId): void
{
    recoverStaleInflightDeletes($chatId);
    $state = loadMessageState();
    $chatState = normalizeChatMessageState($state, $chatId);
    $pending = normalizeMessageIds(
        $chatState['pending_delete'] ?? []
    );
    foreach ($pending as $messageId) {
        $claimToken = claimPendingDelete($chatId, $messageId);
        if ($claimToken === null) continue;
        $result = deleteSavedBotMessage($chatId, $messageId);
        finishInflightDelete(
            $chatId,
            $messageId,
            $claimToken,
            $result
        );
    }
}

function saveMainMenuMessage(int $chatId, int $messageId): void
{
    if ($messageId <= 0) return;
    $saved = false;
    mutateChatMessageState(
        $chatId,
        static function (array &$chatState) use (
            $messageId,
            &$saved
        ): void {
            $inflight = normalizeInflightDeletes(
                $chatState['inflight_delete'] ?? []
            );
            $pending = normalizeMessageIds(
                $chatState['pending_delete'] ?? []
            );
            if (
                isset($inflight[(string)$messageId]) ||
                in_array($messageId, $pending, true)
            ) return;
            $oldId = (int)($chatState['main_menu'] ?? 0);
            if ($oldId > 0 && $oldId !== $messageId) $pending[] = $oldId;
            $chatState['main_menu'] = $messageId;
            $pending = array_values(array_unique($pending));
            if ($pending === []) unset($chatState['pending_delete']);
            else $chatState['pending_delete'] = $pending;
            $saved = true;
        }
    );
    if ($saved) deletePendingBotMessages($chatId);
}

function loadMainMenuMessage(int $chatId): ?int
{
    $state = loadMessageState();
    $chatState = normalizeChatMessageState($state, $chatId);
    $messageId = (int)($chatState['main_menu'] ?? 0);
    return $messageId > 0 ? $messageId : null;
}

function deleteOldMainMenu(int $chatId): void
{
    mutateChatMessageState(
        $chatId,
        static function (array &$chatState): void {
            $messageId = (int)($chatState['main_menu'] ?? 0);
            if ($messageId > 0) {
                $pending = normalizeMessageIds(
                    $chatState['pending_delete'] ?? []
                );
                $pending[] = $messageId;
                $chatState['pending_delete'] =
                    array_values(array_unique($pending));
            }
            unset($chatState['main_menu']);
        }
    );
    deletePendingBotMessages($chatId);
}

function saveOrdersMessages(int $chatId, array $messageIds): void
{
    $newIds = normalizeMessageIds($messageIds);
    $saved = false;
    mutateChatMessageState(
        $chatId,
        static function (array &$chatState) use ($newIds, &$saved): void {
            $inflight = normalizeInflightDeletes(
                $chatState['inflight_delete'] ?? []
            );
            $pending = normalizeMessageIds(
                $chatState['pending_delete'] ?? []
            );
            foreach ($newIds as $messageId) {
                if (
                    isset($inflight[(string)$messageId]) ||
                    in_array($messageId, $pending, true)
                ) return;
            }
            $oldIds = normalizeMessageIds($chatState['orders'] ?? []);
            $obsolete = array_values(array_diff($oldIds, $newIds));
            $pending = array_values(array_unique(array_merge(
                $pending,
                $obsolete
            )));
            if ($newIds === []) unset($chatState['orders']);
            else $chatState['orders'] = $newIds;
            if ($pending === []) unset($chatState['pending_delete']);
            else $chatState['pending_delete'] = $pending;
            $saved = true;
        }
    );
    if ($saved) deletePendingBotMessages($chatId);
}

function loadOrdersMessages(int $chatId): array
{
    $state = loadMessageState();
    $chatState = normalizeChatMessageState($state, $chatId);
    return normalizeMessageIds($chatState['orders'] ?? []);
}

function deleteOldOrdersMessages(int $chatId): void
{
    mutateChatMessageState(
        $chatId,
        static function (array &$chatState): void {
            $ids = normalizeMessageIds($chatState['orders'] ?? []);
            if ($ids !== []) {
                $pending = normalizeMessageIds(
                    $chatState['pending_delete'] ?? []
                );
                $chatState['pending_delete'] =
                    array_values(array_unique(array_merge($pending, $ids)));
            }
            unset($chatState['orders']);
        }
    );
    deletePendingBotMessages($chatId);
}

function saveOrderCardMessage(int $chatId, int $messageId): void
{
    if ($messageId <= 0) return;
    $saved = false;
    mutateChatMessageState(
        $chatId,
        static function (array &$chatState) use (
            $messageId,
            &$saved
        ): void {
            $inflight = normalizeInflightDeletes(
                $chatState['inflight_delete'] ?? []
            );
            $pending = normalizeMessageIds(
                $chatState['pending_delete'] ?? []
            );
            if (
                isset($inflight[(string)$messageId]) ||
                in_array($messageId, $pending, true)
            ) return;
            $oldId = (int)($chatState['order_card'] ?? 0);
            if ($oldId > 0 && $oldId !== $messageId) $pending[] = $oldId;
            $chatState['order_card'] = $messageId;
            $pending = array_values(array_unique($pending));
            if ($pending === []) unset($chatState['pending_delete']);
            else $chatState['pending_delete'] = $pending;
            $saved = true;
        }
    );
    if ($saved) deletePendingBotMessages($chatId);
}

function loadOrderCardMessage(int $chatId): ?int
{
    $state = loadMessageState();
    $chatState = normalizeChatMessageState($state, $chatId);
    $messageId = (int)($chatState['order_card'] ?? 0);
    return $messageId > 0 ? $messageId : null;
}

function deleteOldOrderCard(int $chatId): void
{
    mutateChatMessageState(
        $chatId,
        static function (array &$chatState): void {
            $messageId = (int)($chatState['order_card'] ?? 0);
            if ($messageId > 0) {
                $pending = normalizeMessageIds(
                    $chatState['pending_delete'] ?? []
                );
                $pending[] = $messageId;
                $chatState['pending_delete'] =
                    array_values(array_unique($pending));
            }
            unset($chatState['order_card']);
        }
    );
    deletePendingBotMessages($chatId);
}

function saveTransientMessage(int $chatId, int $messageId): void
{
    if ($messageId <= 0) return;

    $saved = false;

    mutateChatMessageState(
        $chatId,
        static function (array &$chatState) use (
            $messageId,
            &$saved
        ): void {
            $inflight = normalizeInflightDeletes(
                $chatState['inflight_delete'] ?? []
            );
            $pending = normalizeMessageIds(
                $chatState['pending_delete'] ?? []
            );

            if (
                isset($inflight[(string)$messageId]) ||
                in_array($messageId, $pending, true)
            ) {
                return;
            }

            $messageIds = normalizeMessageIds(
                $chatState['transient_messages'] ?? []
            );

            if (in_array($messageId, $messageIds, true)) {
                return;
            }

            $messageIds[] = $messageId;
            $chatState['transient_messages'] = $messageIds;
            $saved = true;
        }
    );

    if ($saved) {
        deletePendingBotMessages($chatId);
    }
}

function deleteOldTransientMessages(int $chatId): void
{
    mutateChatMessageState(
        $chatId,
        static function (array &$chatState): void {
            $messageIds = normalizeMessageIds(
                $chatState['transient_messages'] ?? []
            );

            if ($messageIds !== []) {
                $pending = normalizeMessageIds(
                    $chatState['pending_delete'] ?? []
                );

                $chatState['pending_delete'] =
                    array_values(array_unique(array_merge(
                        $pending,
                        $messageIds
                    )));
            }

            unset($chatState['transient_messages']);
        }
    );

    deletePendingBotMessages($chatId);
}

function sendTransientMessage(int $chatId, array $data): ?array
{
    $response = telegramRequest('sendMessage', $data);
    $messageId = extractSentMessageId($response);

    if ($messageId !== null) {
        saveTransientMessage($chatId, $messageId);
    }

    return $response;
}

function telegramSendDocumentFile(
    int $chatId,
    string $filePath,
    string $caption = ''
): ?array {
    $telegramBotToken =
        requireTelvoraSecret('telegram_bot_token');

    $url =
        'https://api.telegram.org/bot' .
        $telegramBotToken .
        '/sendDocument';

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_POSTFIELDS => [
            'chat_id' => (string)$chatId,
            'caption' => $caption,
            'document' => new CURLFile(
                $filePath,
                'application/pdf',
                basename($filePath)
            )
        ]
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        curl_close($ch);
        return null;
    }

    curl_close($ch);

    $decoded = json_decode($response, true);

    return is_array($decoded) ? $decoded : null;
}

function buildMainMenuKeyboard(int $newOrdersCount): array
{
    return [
        'inline_keyboard' => [
            [[
                'text' => "🆕 Новые заказы ({$newOrdersCount})",
                'callback_data' => 'new_orders'
            ]],
            [[
                'text' => '📦 Последние заказы',
                'callback_data' => 'recent_orders'
            ]],
            [[
                'text' => '📋 Заказы',
                'callback_data' => 'orders_menu'
            ]],
            [[
                'text' => 'ℹ️ Помощь',
                'callback_data' => 'help_menu'
            ]]
        ]
    ];
}

function sendMainMenu(int $chatId): void
{
    deleteOldTransientMessages($chatId);
    deleteOldMainMenu($chatId);
    deleteOldOrdersMessages($chatId);
    deleteOldOrderCard($chatId);

    try {
        $pdo = getDatabase();
        $stmt = $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'Новый'");
        $newOrdersCount = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $newOrdersCount = 0;
    }

    $response = telegramRequest('sendMessage', [
        'chat_id' => $chatId,
        'text' => "<b>TELVORA BOT</b>\n\nВыбирайте раздел:",
        'parse_mode' => 'HTML',
        'reply_markup' => json_encode(
            buildMainMenuKeyboard($newOrdersCount),
            JSON_UNESCAPED_UNICODE
        )
    ]);

    $messageId = extractSentMessageId($response);

    if ($messageId !== null) {
        saveMainMenuMessage($chatId, $messageId);
    }
}

function sendOrdersMenu(int $chatId): void
{
    deleteOldTransientMessages($chatId);
    deleteOldMainMenu($chatId);
    deleteOldOrdersMessages($chatId);
    deleteOldOrderCard($chatId);

    $messageIds = [];

    try {
        $pdo = getDatabase();
        $stmt = $pdo->query("
            SELECT
                id,
                order_number,
                customer_name,
                phone,
                total,
                status,
                created_at
            FROM orders
            ORDER BY id DESC
            LIMIT 3
        ");
        $orders = $stmt->fetchAll();

        if (!$orders) {
            $response = telegramRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => '📦 Заказов пока нет.'
            ]);

            $messageId = extractSentMessageId($response);

            if ($messageId !== null) {
                $messageIds[] = $messageId;
                saveOrdersMessages($chatId, $messageIds);
            }
        } else {
            foreach ($orders as $order) {
                $orderNumber = $order['order_number'] ?: ('#' . $order['id']);
                $keyboard = [
                    'inline_keyboard' => [
                        [[
                            'text' => '👁 Карточка',
                            'callback_data' => 'view_order_' . $order['id']
                        ]],
                        [
                            [
                                'text' => '✏️ Редактировать',
                                'callback_data' => 'edit_order_' . $order['id']
                            ],
                            [
                                'text' => '📄 PDF',
                                'callback_data' => 'pdf_order_' . $order['id']
                            ]
                        ]
                    ]
                ];
                $text =
                    "📦 <b>" . h($orderNumber) . "</b>\n" .
                    "👤 " . h($order['customer_name']) . "\n" .
                    "📞 " . h($order['phone']) . "\n" .
                    "💰 " . money($order['total']) . "\n" .
                    "📌 " . h($order['status']) . "\n" .
                    "📅 " . h($order['created_at']);

                $response = telegramRequest('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode($keyboard, JSON_UNESCAPED_UNICODE)
                ]);

                $messageId = extractSentMessageId($response);

                if ($messageId !== null) {
                    $messageIds[] = $messageId;
                    saveOrdersMessages($chatId, $messageIds);
                }
            }
        }
    } catch (Throwable $e) {
        $response = telegramRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' =>
                '❌ Не удалось получить данные заказов. Попробуйте позже.',
            'parse_mode' => 'HTML'
        ]);

        $messageId = extractSentMessageId($response);

        if ($messageId !== null) {
            $messageIds[] = $messageId;
            saveOrdersMessages($chatId, $messageIds);
        }
    }

    $response = telegramRequest('sendMessage', [
        'chat_id' => $chatId,
        'text' => 'Навигация:',
        'reply_markup' => json_encode([
            'inline_keyboard' => [
                [[
                    'text' => '⬅️ Назад в главное меню',
                    'callback_data' => 'back_main'
                ]]
            ]
        ], JSON_UNESCAPED_UNICODE)
    ]);

    $messageId = extractSentMessageId($response);

    if ($messageId !== null) {
        $messageIds[] = $messageId;
        saveOrdersMessages($chatId, $messageIds);
    }
}

function sendOrderCard(int $chatId, int $orderId): void
{
    deleteOldTransientMessages($chatId);
    deleteOldMainMenu($chatId);
    deleteOldOrdersMessages($chatId);
    deleteOldOrderCard($chatId);

    try {
        $pdo = getDatabase();
        $stmt = $pdo->prepare("
            SELECT
                id,
                order_number,
                customer_name,
                phone,
                email,
                address,
                delivery_time,
                delivery_method,
                payment_method,
                comment,
                total,
                status,
                created_at
            FROM orders
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $orderId]);
        $order = $stmt->fetch();

        if (!$order) {
            $response = telegramRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => '❌ Заказ не найден.'
            ]);

            $messageId = extractSentMessageId($response);

            if ($messageId !== null) {
                saveOrderCardMessage($chatId, $messageId);
            }

            return;
        }

        $orderNumber = $order['order_number'] ?: ('#' . $order['id']);
        $keyboard = [
            'inline_keyboard' => [
                [
                    [
                        'text' => '✏️ Редактировать',
                        'callback_data' => 'edit_order_' . $orderId
                    ],
                    [
                        'text' => '📄 PDF',
                        'callback_data' => 'pdf_order_' . $orderId
                    ]
                ],
                [[
                    'text' => '⬅️ Назад к заказам',
                    'callback_data' => 'back_orders'
                ]],
                [[
                    'text' => '🏠 Главное меню',
                    'callback_data' => 'back_main'
                ]]
            ]
        ];
        $text =
            "📦 <b>ЗАКАЗ " . h($orderNumber) . "</b>\n\n" .
            "👤 " . h($order['customer_name']) . "\n" .
            "📞 " . h($order['phone']) . "\n" .
            "📧 " . h($order['email'] ?: '—') . "\n" .
            "📍 " . h($order['address'] ?: '—') . "\n" .
            "🕐 " . h($order['delivery_time'] ?: '—') . "\n" .
            "🚚 " . h($order['delivery_method'] ?: '—') . "\n" .
            "💳 " . h($order['payment_method'] ?: '—') . "\n" .
            "💬 " . h($order['comment'] ?: '—') . "\n" .
            "💰 " . money($order['total']) . "\n" .
            "📌 " . h($order['status']) . "\n" .
            "📅 " . h($order['created_at']);

        $response = telegramRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard, JSON_UNESCAPED_UNICODE)
        ]);

        $messageId = extractSentMessageId($response);

        if ($messageId !== null) {
            saveOrderCardMessage($chatId, $messageId);
        }
    } catch (Throwable $e) {
        $response = telegramRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => '❌ Не удалось открыть заказ. Попробуйте позже.',
            'parse_mode' => 'HTML'
        ]);

        $messageId = extractSentMessageId($response);

        if ($messageId !== null) {
            saveOrderCardMessage($chatId, $messageId);
        }
    }
}


// ============================================================
// ПОЛУЧАЕМ ОБНОВЛЕНИЯ
// ============================================================

$offsetFile = telegramOffsetFile();

$offset = 0;

if (file_exists($offsetFile)) {
    $offset = (int)trim(file_get_contents($offsetFile));
}

$result = telegramRequest('getUpdates', [
    'offset' => $offset,
    'limit' => 10,
    'timeout' => 0
]);

if (!$result || empty($result['ok'])) {
    exit;
}

$updates = $result['result'] ?? [];


// ============================================================
// ОБРАБОТКА СООБЩЕНИЙ
// ============================================================

foreach ($updates as $update) {

    $updateId = (int)($update['update_id'] ?? 0);

    if ($updateId > 0) {
        file_put_contents(
            $offsetFile,
            (string)($updateId + 1),
            LOCK_EX
        );
    }

        // ========================================================
    // CALLBACK-КНОПКИ
    // ========================================================

    if (isset($update['callback_query'])) {

        $callback = $update['callback_query'];

        $callbackId = $callback['id'] ?? '';
        $callbackUserId = $callback['from']['id'] ?? null;

        if (!isTelegramUserAllowed($callbackUserId)) {
            if ($callbackId !== '') {
                telegramRequest('answerCallbackQuery', [
                    'callback_query_id' => $callbackId,
                    'text' => 'Доступ запрещён'
                ]);
            }

            continue;
        }

        $callbackData = $callback['data'] ?? '';
        $callbackChatId =
            $callback['message']['chat']['id'] ?? null;

        if ($callbackId) {
            telegramRequest('answerCallbackQuery', [
                'callback_query_id' => $callbackId
            ]);
        }

        if (!$callbackChatId) {
            continue;
        }

        // ----------------------------------------------------
        if ($callbackData === 'orders_menu') {
            clearEditState((int)$callbackChatId);
            sendOrdersMenu((int)$callbackChatId);
            continue;
        }

        if ($callbackData === 'new_orders') {
            clearEditState((int)$callbackChatId);
            deleteOldTransientMessages((int)$callbackChatId);
            try {
                $pdo = getDatabase();
                $stmt = $pdo->query("SELECT id, order_number, customer_name, phone, total, status, created_at FROM orders WHERE status = 'Новый' ORDER BY id DESC LIMIT 20");
                $orders = $stmt->fetchAll();

                $text = "🆕 <b>НОВЫЕ ЗАКАЗЫ</b>\n\n";

                if (!$orders) {
                    $text .= "Новых заказов нет.";
                } else {
                    $text .= "Найдено: <b>" . count($orders) . "</b>\n\n";

                    foreach ($orders as $order) {
                        $number = $order['order_number'] ?: ('#' . $order['id']);

                        $text .=
                            "📦 <b>" . h($number) . "</b>\n" .
                            "👤 " . h($order['customer_name']) . "\n" .
                            "📱 " . h($order['phone']) . "\n" .
                            "💰 " . money($order['total']) . "\n" .
                            "🕐 " . h($order['created_at']) . "\n\n";
                    }
                }

                $keyboard=[
                    'inline_keyboard'=>[
                        [
                            [
                                'text'=>"⬅️ Назад",
                                'callback_data'=>'back_main'
                            ]
                        ]
                    ]
                ];

                sendTransientMessage((int)$callbackChatId, [
                    'chat_id'=>$callbackChatId,
                    'text'=>$text,
                    'parse_mode'=>'HTML',
                    'reply_markup'=>json_encode($keyboard,JSON_UNESCAPED_UNICODE)
                ]);

            } catch (Throwable $e) {
                sendTransientMessage((int)$callbackChatId, [
                    'chat_id'=>$callbackChatId,
                    'text'=>
                        '❌ Не удалось получить данные заказов. Попробуйте позже.',
                    'parse_mode'=>'HTML'
                ]);
            }

            continue;
        }

        if ($callbackData === 'recent_orders') {
            clearEditState((int)$callbackChatId);
            deleteOldTransientMessages((int)$callbackChatId);
            try {
                $pdo=getDatabase();

                $stmt=$pdo->query("
                    SELECT id, order_number, customer_name, phone, total, status, created_at
                    FROM orders
                    ORDER BY id DESC
                    LIMIT 10
                ");

                $orders=$stmt->fetchAll();

                $text="📦 <b>ПОСЛЕДНИЕ ЗАКАЗЫ</b>\n\n";

                if (!$orders) {
                    $text.="Заказов пока нет.";
                } else {
                    foreach ($orders as $order) {
                        $number=$order['order_number'] ?: ('#'.$order['id']);

                        $text.=
                            "📦 <b>".h($number)."</b>\n".
                            "👤 ".h($order['customer_name'])."\n".
                            "📱 ".h($order['phone'])."\n".
                            "💰 ".money($order['total'])."\n".
                            "📌 ".h($order['status'])."\n".
                            "🕐 ".h($order['created_at'])."\n\n";
                    }
                }

                $keyboard=[
                    'inline_keyboard'=>[
                        [
                            [
                                'text'=>"⬅️ Назад",
                                'callback_data'=>'back_main'
                            ]
                        ]
                    ]
                ];

                sendTransientMessage((int)$callbackChatId, [
                    'chat_id'=>$callbackChatId,
                    'text'=>$text,
                    'parse_mode'=>'HTML',
                    'reply_markup'=>json_encode($keyboard,JSON_UNESCAPED_UNICODE)
                ]);

            } catch (Throwable $e) {
                sendTransientMessage((int)$callbackChatId, [
                    'chat_id'=>$callbackChatId,
                    'text'=>
                        '❌ Не удалось получить данные заказов. Попробуйте позже.',
                    'parse_mode'=>'HTML'
                ]);
            }

            continue;
        }

        if ($callbackData === 'back_main') {
            clearEditState((int)$callbackChatId);
            sendMainMenu((int)$callbackChatId);
            continue;
        }

        if ($callbackData === 'back_orders') {
            clearEditState((int)$callbackChatId);
            sendOrdersMenu((int)$callbackChatId);
            continue;
        }

        if ($callbackData === 'help_menu') {
            clearEditState((int)$callbackChatId);
            deleteOldTransientMessages((int)$callbackChatId);
            sendTransientMessage((int)$callbackChatId, [
                'chat_id' => $callbackChatId,
                'text' =>
                    "📋 <b>Команды TELVORA BOT</b>\n\n" .
                    "/start — главное меню\n" .
                    "/orders — последние заказы\n" .
                    "/help — помощь",
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [[
                            'text' => '🏠 Главное меню',
                            'callback_data' => 'back_main'
                        ]]
                    ]
                ], JSON_UNESCAPED_UNICODE)
            ]);
            continue;
        }

        if (preg_match('/^view_order_(\d+)$/', $callbackData, $m)) {
            clearEditState((int)$callbackChatId);
            sendOrderCard((int)$callbackChatId, (int)$m[1]);
            continue;
        }

        
        // ОТКРЫТЬ РЕДАКТИРОВАНИЕ ЗАКАЗА
        // ----------------------------------------------------

        if (preg_match('/^edit_order_(\d+)$/', $callbackData, $m)) {

            $orderId = (int)$m[1];

            clearEditState((int)$callbackChatId);
            deleteOldTransientMessages((int)$callbackChatId);
            deleteOldMainMenu((int)$callbackChatId);
            deleteOldOrdersMessages((int)$callbackChatId);
            deleteOldOrderCard((int)$callbackChatId);

            try {

                $pdo = getDatabase();

                $stmt = $pdo->prepare("
                    SELECT
                        id,
                        order_number,
                        customer_name,
                        phone,
                        email,
                        address,
                        delivery_time,
                        delivery_method,
                        payment_method,
                        comment,
                        total,
                        status
                    FROM orders
                    WHERE id = :id
                    LIMIT 1
                ");

                $stmt->execute([
                    ':id' => $orderId
                ]);

                $order = $stmt->fetch();

                if (!$order) {

                    $response = telegramRequest('sendMessage', [
                        'chat_id' => $callbackChatId,
                        'text' => '❌ Заказ не найден.'
                    ]);

                    $messageId = extractSentMessageId($response);

                    if ($messageId !== null) {
                        saveOrderCardMessage((int)$callbackChatId, $messageId);
                    }

                } else {

                    $orderNumber =
                        $order['order_number']
                        ?: ('#' . $order['id']);

                    $keyboard = [
                        'inline_keyboard' => [
                            [
                                [
                                    'text' => 'Покупатель',
                                    'callback_data' => 'edit_field_customer_name_' . $orderId
                                ],
                                [
                                    'text' => 'Телефон',
                                    'callback_data' => 'edit_field_phone_' . $orderId
                                ]
                            ],
                            [
                                [
                                    'text' => 'Email',
                                    'callback_data' => 'edit_field_email_' . $orderId
                                ],
                                [
                                    'text' => 'Адрес',
                                    'callback_data' => 'edit_field_address_' . $orderId
                                ]
                            ],
                            [
                                [
                                    'text' => 'Время доставки',
                                    'callback_data' => 'edit_field_delivery_time_' . $orderId
                                ]
                            ],
                            [
                                [
                                    'text' => 'Комментарий',
                                    'callback_data' => 'edit_field_comment_' . $orderId
                                ]
                            ],
                            [
                                [
                                    'text' => 'Доставка',
                                    'callback_data' => 'edit_field_delivery_method_' . $orderId
                                ],
                                [
                                    'text' => 'Оплата',
                                    'callback_data' => 'edit_field_payment_method_' . $orderId
                                ]
                            ],
                            [
                                [
                                    'text' => 'Статус',
                                    'callback_data' => 'edit_field_status_' . $orderId
                                ]
                            ],
                            [
                                [
                                    'text' => 'Товары',
                                    'callback_data' => 'items_order_' . $orderId
                                ]
                            ],
                            [
                                [
                                    'text' => 'Получить PDF',
                                    'callback_data' => 'pdf_order_' . $orderId
                                ]
                            ],
                            [
                                [
                                    'text' => '⬅️ Назад к заказу',
                                    'callback_data' => 'view_order_' . $orderId
                                ]
                            ],
                            [
                                [
                                    'text' => '📋 Назад к заказам',
                                    'callback_data' => 'back_orders'
                                ],
                                [
                                    'text' => '🏠 Главное меню',
                                    'callback_data' => 'back_main'
                                ]
                            ]
                        ]
                    ];

                    $response = telegramRequest('sendMessage', [
                        'chat_id' => $callbackChatId,
                        'text' =>
                            "✏️ <b>РЕДАКТИРОВАНИЕ НАКЛАДНОЙ</b>\n\n" .
                            "Заказ: <b>" . h($orderNumber) . "</b>\n" .
                            "👤 " . h($order['customer_name']) . "\n" .
                            "📞 " . h($order['phone']) . "\n" .
                            "📍 " . h($order['address'] ?: '—') . "\n" .
                            "🕐 " . h($order['delivery_time'] ?: '—') . "\n" .
                            "💰 " . money($order['total']) . "\n\n" .
                            "Выбери, что изменить:",
                        'parse_mode' => 'HTML',
                        'reply_markup' => json_encode(
                            $keyboard,
                            JSON_UNESCAPED_UNICODE
                        )
                    ]);

                    $messageId = extractSentMessageId($response);

                    if ($messageId !== null) {
                        saveOrderCardMessage((int)$callbackChatId, $messageId);
                    }
                }

            } catch (Throwable $e) {

                $response = telegramRequest('sendMessage', [
                    'chat_id' => $callbackChatId,
                    'text' =>
                        '❌ Не удалось открыть заказ. Попробуйте позже.',
                    'parse_mode' => 'HTML'
                ]);

                $messageId = extractSentMessageId($response);

                if ($messageId !== null) {
                    saveOrderCardMessage((int)$callbackChatId, $messageId);
                }
            }

            continue;
        }

        // ----------------------------------------------------
        // ВЫБОР ПОЛЯ ДЛЯ РЕДАКТИРОВАНИЯ
        // ----------------------------------------------------

        if (preg_match(
            '/^edit_field_(customer_name|phone|email|address|delivery_time|comment|delivery_method|payment_method|status)_(\d+)$/',
            $callbackData,
            $m
        )) {

            $field = $m[1];
            $orderId = (int)$m[2];

            $labels = [
                'customer_name' =>
                    '👤 Введите новое имя покупателя:',
                'phone' =>
                    '📞 Введите новый телефон:',
                'email' =>
                    '📧 Введите новый Email:',
                'address' =>
                    '📍 Введите новый адрес:',
                'delivery_time' =>
                    '🕐 Введите новое время доставки:',
                'comment' =>
                    '💬 Введите новый комментарий:',
                'delivery_method' =>
                    '🚚 Введите способ доставки:',
                'payment_method' =>
                    '💳 Введите новый способ оплаты:',
                'status' =>
                    '📌 Введите новый статус:'
            ];

            saveEditState((int)$callbackChatId, [
                'type' => 'order_field',
                'order_id' => $orderId,
                'field' => $field
            ]);

            deleteOldTransientMessages((int)$callbackChatId);

            sendTransientMessage((int)$callbackChatId, [
                'chat_id' => $callbackChatId,
                'text' =>
                    ($labels[$field] ?? 'Введите новое значение:') .
                    "\n\n❌ Для отмены: /cancel",
                'parse_mode' => 'HTML'
            ]);

            continue;
        }

        // ----------------------------------------------------
        // СПИСОК ТОВАРОВ
        // ----------------------------------------------------

        if (preg_match('/^items_order_(\d+)$/', $callbackData, $m)) {

            $orderId = (int)$m[1];

            clearEditState((int)$callbackChatId);
            deleteOldTransientMessages((int)$callbackChatId);

            try {

                $pdo = getDatabase();

                $stmt = $pdo->prepare("
                    SELECT
                        id,
                        product_name,
                        quantity,
                        price
                    FROM order_items
                    WHERE order_id = :order_id
                    ORDER BY id ASC
                ");

                $stmt->execute([
                    ':order_id' => $orderId
                ]);

                $items = $stmt->fetchAll();

                if (!$items) {

                    sendTransientMessage((int)$callbackChatId, [
                        'chat_id' => $callbackChatId,
                        'text' => '🛒 В заказе нет товаров.'
                    ]);

                } else {

                    foreach ($items as $item) {

                        $itemId = (int)$item['id'];

                        $keyboard = [
                            'inline_keyboard' => [
                                [
                                    [
                                        'text' => '✏️ Название',
                                        'callback_data' =>
                                            'edit_item_name_' .
                                            $itemId . '_' .
                                            $orderId
                                    ],
                                    [
                                        'text' => '🔢 Кол-во',
                                        'callback_data' =>
                                            'edit_item_quantity_' .
                                            $itemId . '_' .
                                            $orderId
                                    ]
                                ],
                                [
                                    [
                                        'text' => '💰 Цена',
                                        'callback_data' =>
                                            'edit_item_price_' .
                                            $itemId . '_' .
                                            $orderId
                                    ]
                                ]
                            ]
                        ];

                        sendTransientMessage((int)$callbackChatId, [
                            'chat_id' => $callbackChatId,
                            'text' =>
                                "🛒 <b>" .
                                h($item['product_name']) .
                                "</b>\n\n" .
                                "Количество: " .
                                (int)$item['quantity'] . "\n" .
                                "Цена: " .
                                money($item['price']),
                            'parse_mode' => 'HTML',
                            'reply_markup' => json_encode(
                                $keyboard,
                                JSON_UNESCAPED_UNICODE
                            )
                        ]);
                    }
                }

            } catch (Throwable $e) {

                sendTransientMessage((int)$callbackChatId, [
                    'chat_id' => $callbackChatId,
                    'text' =>
                        '❌ Не удалось получить данные товаров. Попробуйте позже.',
                    'parse_mode' => 'HTML'
                ]);
            }

            continue;
        }

        // ----------------------------------------------------
        // РЕДАКТИРОВАНИЕ ТОВАРА
        // ----------------------------------------------------

        if (preg_match(
            '/^edit_item_(name|quantity|price)_(\d+)_(\d+)$/',
            $callbackData,
            $m
        )) {

            $itemField = $m[1];
            $itemId = (int)$m[2];
            $orderId = (int)$m[3];

            $fieldMap = [
                'name' => 'product_name',
                'quantity' => 'quantity',
                'price' => 'price'
            ];

            $labels = [
                'name' =>
                    '✏️ Введите новое название товара:',
                'quantity' =>
                    '🔢 Введите новое количество:',
                'price' =>
                    '💰 Введите новую цену:'
            ];

            saveEditState((int)$callbackChatId, [
                'type' => 'item_field',
                'order_id' => $orderId,
                'item_id' => $itemId,
                'field' => $fieldMap[$itemField]
            ]);

            deleteOldTransientMessages((int)$callbackChatId);

            sendTransientMessage((int)$callbackChatId, [
                'chat_id' => $callbackChatId,
                'text' =>
                    $labels[$itemField] .
                    "\n\n❌ Для отмены: /cancel"
            ]);

            continue;
        }

        // ----------------------------------------------------
        // PDF
        // ----------------------------------------------------

        if (preg_match('/^pdf_order_(\d+)$/', $callbackData, $m)) {

            $orderId = (int)$m[1];

            $tempFile =
                sys_get_temp_dir() .
                '/TELVORA_' .
                $orderId .
                '_' .
                uniqid() .
                '.pdf';

            $pdfUrl =
                'https://telvora.ru/generate_invoice_pdf.php?order_id=' .
                $orderId;

            $pdfTimestamp = (string)time();
            $pdfSignature = hash_hmac(
                'sha256',
                'telvora-pdf|' . $orderId . '|' . $pdfTimestamp,
                requireTelvoraSecret('telegram_bot_token')
            );

            $fp = fopen($tempFile, 'wb');

            if (!$fp) {

                telegramRequest('sendMessage', [
                    'chat_id' => $callbackChatId,
                    'text' =>
                        '❌ Не удалось сформировать документ. Попробуйте позже.'
                ]);

                continue;
            }

            $ch = curl_init($pdfUrl);

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_FILE => $fp,
                CURLOPT_HTTPHEADER => [
                    'X-Telvora-Pdf-Timestamp: ' . $pdfTimestamp,
                    'X-Telvora-Pdf-Signature: ' . $pdfSignature
                ]
            ]);

            $ok = curl_exec($ch);

            $httpCode = curl_getinfo(
                $ch,
                CURLINFO_HTTP_CODE
            );

            curl_close($ch);
            fclose($fp);

            if (!$ok || $httpCode !== 200 || !file_exists($tempFile) || filesize($tempFile) < 100) {

                @unlink($tempFile);

                telegramRequest('sendMessage', [
                    'chat_id' => $callbackChatId,
                    'text' =>
                        '❌ Не удалось сформировать документ. Попробуйте позже.'
                ]);

                continue;
            }

            $result = telegramSendDocumentFile(
                (int)$callbackChatId,
                $tempFile,
                '📄 Накладная TELVORA'
            );

            @unlink($tempFile);

            if (!$result || empty($result['ok'])) {

                telegramRequest('sendMessage', [
                    'chat_id' => $callbackChatId,
                    'text' =>
                        '❌ Не удалось сформировать документ. Попробуйте позже.'
                ]);
            }

            continue;
        }

                continue;
    }


    // ========================================================
    // ОБЫЧНОЕ СООБЩЕНИЕ
    // ========================================================

    if (!isset($update['message'])) {
        continue;
    }

    $message = $update['message'];

    $messageUserId = $message['from']['id'] ?? null;

    if (!isTelegramUserAllowed($messageUserId)) {
        continue;
    }

    $chatId = $message['chat']['id'] ?? null;
    $text = trim($message['text'] ?? '');

    if (!$chatId) {
        continue;
    }

    $editState = loadEditState((int)$chatId);

    $isTelegramCommand = preg_match(
        '/^\/[A-Za-z0-9_]+(?:@[A-Za-z0-9_]+)?(?:\s|$)/D',
        $text
    ) === 1;

    if (
        $editState &&
        $isTelegramCommand
    ) {
        clearEditState((int)$chatId);
        $editState = null;

        if ($text === '/cancel') {
            deleteOldTransientMessages((int)$chatId);

            sendTransientMessage((int)$chatId, [
                'chat_id' => $chatId,
                'text' => '✅ Редактирование отменено.'
            ]);
            continue;
        }
    }

    if ($editState) {
        $stateType = $editState['type'] ?? '';
        $stateField = $editState['field'] ?? '';
        $stateOrderId = (int)($editState['order_id'] ?? 0);
        $stateItemId = (int)($editState['item_id'] ?? 0);

        $validOrderState =
            $stateType === 'order_field' &&
            $stateOrderId > 0 &&
            in_array(
                $stateField,
                [
                    'customer_name',
                    'phone',
                    'email',
                    'address',
                    'delivery_time',
                    'comment',
                    'delivery_method',
                    'payment_method',
                    'status'
                ],
                true
            );

        $validItemState =
            $stateType === 'item_field' &&
            $stateOrderId > 0 &&
            $stateItemId > 0 &&
            in_array(
                $stateField,
                ['product_name', 'quantity', 'price'],
                true
            );

        if (!$validOrderState && !$validItemState) {
            clearEditState((int)$chatId);
            $editState = null;
        }
    }
    // ========================================================
    // СОХРАНЕНИЕ РЕДАКТИРОВАНИЯ
    // ========================================================

    if ($text !== '') {

        if ($editState) {

            $type = $editState['type'] ?? '';

            try {

                $pdo = getDatabase();

                // ------------------------------------------------
                // РЕДАКТИРОВАНИЕ ПОЛЯ ЗАКАЗА
                // ------------------------------------------------

                if ($type === 'order_field') {

                    $orderId = (int)($editState['order_id'] ?? 0);
                    $field = $editState['field'] ?? '';

                    $allowedFields = [
                        'customer_name',
                        'phone',
                        'email',
                        'address',
                        'delivery_time',
                        'comment',
                        'delivery_method',
                        'payment_method',
                        'status'
                    ];

                    if (
                        $orderId > 0 &&
                        in_array($field, $allowedFields, true)
                    ) {

                        $stmt = $pdo->prepare("
                            UPDATE orders
                            SET {$field} = :value
                            WHERE id = :id
                            LIMIT 1
                        ");

                        $stmt->execute([
                            ':value' => $text,
                            ':id' => $orderId
                        ]);

                        $stmtCheck = $pdo->prepare("
    SELECT {$field}
    FROM orders
    WHERE id = :id
    LIMIT 1
");

$stmtCheck->execute([
    ':id' => $orderId
]);

$savedValue = $stmtCheck->fetchColumn();

clearEditState((int)$chatId);

deleteOldTransientMessages((int)$chatId);

sendTransientMessage((int)$chatId, [
    'chat_id' => $chatId,
    'text' =>
        "✅ <b>Сохранено</b>\n\n" .
        "Поле: <b>" . h($field) . "</b>\n" .
        "Значение в базе:\n<b>" .
        h($savedValue) .
        "</b>",
    'parse_mode' => 'HTML'
]);

continue;
                    }
                }

                // ------------------------------------------------
                // РЕДАКТИРОВАНИЕ ТОВАРА
                // ------------------------------------------------

                if ($type === 'item_field') {

                    $itemId = (int)($editState['item_id'] ?? 0);
                    $field = $editState['field'] ?? '';

                    $allowedFields = [
                        'product_name',
                        'quantity',
                        'price'
                    ];

                    if (
                        $itemId > 0 &&
                        in_array($field, $allowedFields, true)
                    ) {

                        if ($field === 'quantity') {

                            $value = max(1, (int)$text);

                        } elseif ($field === 'price') {

                            $clean = preg_replace(
                                '/[^\d,.\-]/u',
                                '',
                                $text
                            );

                            $value = max(
                                0,
                                (float)str_replace(',', '.', $clean)
                            );

                        } else {

                            $value = $text;
                        }

                        $stmt = $pdo->prepare("
                            UPDATE order_items
                            SET {$field} = :value
                            WHERE id = :id
                            LIMIT 1
                        ");

                        $stmt->execute([
                            ':value' => $value,
                            ':id' => $itemId
                        ]);

                        // Пересчитываем сумму заказа
                        $orderStmt = $pdo->prepare("
                            SELECT order_id
                            FROM order_items
                            WHERE id = :id
                            LIMIT 1
                        ");

                        $orderStmt->execute([
                            ':id' => $itemId
                        ]);

                        $item = $orderStmt->fetch();

                        if ($item) {

                            $totalStmt = $pdo->prepare("
                                SELECT COALESCE(
                                    SUM(quantity * price),
                                    0
                                )
                                FROM order_items
                                WHERE order_id = :order_id
                            ");

                            $totalStmt->execute([
                                ':order_id' => $item['order_id']
                            ]);

                            $newTotal =
                                (float)$totalStmt->fetchColumn();

                            $updateTotal = $pdo->prepare("
                                UPDATE orders
                                SET total = :total
                                WHERE id = :id
                                LIMIT 1
                            ");

                            $updateTotal->execute([
                                ':total' => $newTotal,
                                ':id' => $item['order_id']
                            ]);
                        }

                        clearEditState((int)$chatId);

                        deleteOldTransientMessages((int)$chatId);

                        sendTransientMessage((int)$chatId, [
                            'chat_id' => $chatId,
                            'text' =>
                                "✅ <b>Товар обновлён</b>\n\n" .
                                "Количество/цена/название сохранены.\n" .
                                "Итог заказа пересчитан.",
                            'parse_mode' => 'HTML'
                        ]);

                        continue;
                    }
                }

            } catch (Throwable $e) {

                clearEditState((int)$chatId);
                deleteOldTransientMessages((int)$chatId);

                sendTransientMessage((int)$chatId, [
                    'chat_id' => $chatId,
                    'text' =>
                        '❌ Не удалось сохранить изменения. Попробуйте позже.',
                    'parse_mode' => 'HTML'
                ]);

                continue;
            }
        }
    }


    // ========================================================
    // START
    // ========================================================

    if ($text === '/start') {
        sendMainMenu((int)$chatId);
        continue;
    }


    // ========================================================
    // HELP
    // ========================================================

    if ($text === '/help') {

        deleteOldTransientMessages((int)$chatId);

        sendTransientMessage((int)$chatId, [
            'chat_id' => $chatId,
            'text' =>
                "📋 <b>Команды TELVORA BOT</b>\n\n" .
                "/start — главное меню\n" .
                "/orders — последние заказы\n" .
                "/help — помощь",
            'parse_mode' => 'HTML'
        ]);

        continue;
    }


    // ========================================================
    // ORDERS
    // ========================================================

    if ($text === '/orders') {
        sendOrdersMenu((int)$chatId);
        continue;
    }
    // ========================================================
    // UNKNOWN
    // ========================================================

    deleteOldTransientMessages((int)$chatId);

    sendTransientMessage((int)$chatId, [
        'chat_id' => $chatId,
        'text' =>
            "❓ Неизвестная команда.\n\n" .
            "Используй /help",
        'parse_mode' => 'HTML'
    ]);
}

exit;
