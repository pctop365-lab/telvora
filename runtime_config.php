<?php

declare(strict_types=1);

function telvoraRuntimeIsIsolatedHttpTest(): bool
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    return PHP_SAPI === 'cli-server'
        && getenv('TELVORA_HTTP_TEST_MODE') === 'stage12le-isolated'
        && in_array($remote, ['127.0.0.1', '::1'], true);
}

function telvoraSecretsFile(): string
{
    if (!telvoraRuntimeIsIsolatedHttpTest()) {
        return dirname(__DIR__, 2) . '/telvora_runtime/telvora_secrets.php';
    }
    $path = getenv('TELVORA_HTTP_TEST_SECRETS_FILE');
    if (!is_string($path) || $path === '' || basename($path) !== 'stage12le-test-secrets.php') {
        return '';
    }
    return $path;
}

function telvoraRuntimeFile(string $name): string
{
    if (!telvoraRuntimeIsIsolatedHttpTest()) {
        return dirname(__DIR__, 2) . '/telvora_runtime/' . $name;
    }
    $directory = getenv('TELVORA_HTTP_TEST_RUNTIME_DIR');
    if (!is_string($directory) || $directory === '' || !is_dir($directory)) return '';
    return rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . basename($name);
}
