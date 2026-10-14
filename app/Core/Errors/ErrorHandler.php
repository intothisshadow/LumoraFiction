<?php

/**
 * Converts PHP errors to exceptions, logs every failure to disk, and shows visitors only a generic page.
 *
 * @package LumoraFiction
 * @subpackage Errors
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Errors;

use ErrorException;
use Throwable;

/**
 * Detailed errors go to the log file, never to the response, unless debug mode is on. Debug mode
 * exists for local development only: it prints file paths and stack traces.
 */
final class ErrorHandler
{
    /**
     * @param bool|null $cli true to force plain-text output, false to force HTML, null to detect the SAPI
     */
    public function __construct(
        private readonly string $logDirectory,
        private bool $debug = false,
        private readonly ?bool $cli = null,
    ) {
    }

    public function setDebug(bool $debug): void
    {
        $this->debug = $debug;
    }

    public function register(): void
    {
        $this->configureRuntime();

        set_error_handler($this->handleError(...));
        set_exception_handler($this->handleException(...));
        register_shutdown_function($this->handleShutdown(...));
    }

    public function configureRuntime(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '0');

        // Stack traces are written to the log, and with arguments included they would contain
        // values such as the database password passed to the connection call.
        ini_set('zend.exception_ignore_args', '1');
    }

    /**
     * @throws ErrorException
     */
    public function handleError(int $severity, string $message, string $file, int $line): bool
    {
        // Respect the @ operator and any narrowed error_reporting level.
        if (!(error_reporting() & $severity)) {
            return false;
        }

        throw new ErrorException($message, 0, $severity, $file, $line);
    }

    public function handleException(Throwable $exception): void
    {
        $this->report($exception);
        $this->render($exception);
    }

    public function handleShutdown(): void
    {
        $error = error_get_last();

        if ($error === null) {
            return;
        }

        $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

        if (!in_array($error['type'], $fatal, true)) {
            return;
        }

        $this->handleException(new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']));
    }

    /**
     * Writes the failure to the log without producing any output.
     */
    public function report(Throwable $exception): void
    {
        $entry = sprintf(
            "[%s] %s: %s in %s:%d\n%s\n\n",
            date('Y-m-d H:i:s'),
            $exception::class,
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTraceAsString(),
        );

        if (is_dir($this->logDirectory) && is_writable($this->logDirectory)) {
            error_log($entry, 3, rtrim($this->logDirectory, '/') . '/error.log');

            return;
        }

        // The log directory is unusable, so fall back to the server's own error log
        // rather than losing the failure entirely.
        error_log($entry);
    }

    private function render(Throwable $exception): void
    {
        $cli = $this->cli ?? PHP_SAPI === 'cli';

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: ' . ($cli ? 'text/plain' : 'text/html') . '; charset=utf-8');
        }

        if ($cli) {
            echo $this->debug
                ? $exception::class . ': ' . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n"
                : "An unexpected error occurred.\n";

            return;
        }

        echo $this->debug ? $this->renderDebug($exception) : $this->renderGeneric();
    }

    private function renderGeneric(): string
    {
        return <<<'HTML'
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>Something went wrong</title>
            </head>
            <body>
                <h1>Something went wrong</h1>
                <p>An unexpected error occurred. Please try again later.</p>
            </body>
            </html>
            HTML;
    }

    private function renderDebug(Throwable $exception): string
    {
        $class = htmlspecialchars($exception::class, ENT_QUOTES, 'UTF-8');
        $message = htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8');
        $location = htmlspecialchars($exception->getFile() . ':' . $exception->getLine(), ENT_QUOTES, 'UTF-8');
        $trace = htmlspecialchars($exception->getTraceAsString(), ENT_QUOTES, 'UTF-8');

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <title>{$class}</title>
            </head>
            <body>
                <h1>{$class}</h1>
                <p>{$message}</p>
                <p><code>{$location}</code></p>
                <pre>{$trace}</pre>
            </body>
            </html>
            HTML;
    }
}
