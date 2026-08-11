<?php
namespace Enovos\TicketShop;

if (!defined('ABSPATH')) {
    exit;
}

final class Logger {
    private const OPTION = 'enovos_ticket_shop_log';
    private const MAX_LINES = 800;

    public static function log(string $stage, string $message, array $context = []): void {
        $line = sprintf(
            '[%s] [%s] %s%s',
            current_time('mysql'),
            strtoupper($stage),
            $message,
            $context ? ' | ' . wp_json_encode(self::redact($context), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : ''
        );

        $lines = get_option(self::OPTION, []);
        if (!is_array($lines)) {
            $lines = [];
        }
        $lines[] = $line;
        if (count($lines) > self::MAX_LINES) {
            $lines = array_slice($lines, -self::MAX_LINES);
        }
        update_option(self::OPTION, $lines, false);

        $upload = wp_upload_dir();
        if (empty($upload['error']) && !empty($upload['basedir'])) {
            $dir = trailingslashit($upload['basedir']) . 'enovos-ticket-shop-logs';
            if (!wp_mkdir_p($dir)) {
                error_log('[Enovos Ticket Shop] ' . $line);
                return;
            }
            $file = trailingslashit($dir) . 'import.log';
            @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        } else {
            error_log('[Enovos Ticket Shop] ' . $line);
        }
    }

    public static function clear(): void {
        delete_option(self::OPTION);
        $upload = wp_upload_dir();
        if (empty($upload['error']) && !empty($upload['basedir'])) {
            $file = trailingslashit($upload['basedir']) . 'enovos-ticket-shop-logs/import.log';
            if (file_exists($file)) {
                @unlink($file);
            }
        }
    }

    public static function lines(int $limit = 250): array {
        $lines = get_option(self::OPTION, []);
        if (!is_array($lines)) {
            return [];
        }
        return array_slice($lines, -$limit);
    }

    public static function path(): string {
        $upload = wp_upload_dir();
        if (!empty($upload['error']) || empty($upload['basedir'])) {
            return '';
        }
        return trailingslashit($upload['basedir']) . 'enovos-ticket-shop-logs/import.log';
    }

    private static function redact(array $context): array {
        foreach (['openai_api_key', 'gemini_api_key', 'custom_ai_token', 'authorization', 'api_key', 'token'] as $key) {
            if (isset($context[$key])) {
                $context[$key] = '[REDACTED]';
            }
        }
        return $context;
    }
}
