<?php
namespace Enovos\TicketShop;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Extract stable ticket metadata directly from PDF page content.
 *
 * This is deliberately a narrow fallback for text-based ticket PDFs. AI remains
 * responsible for enrichment, while this class is the source of truth for page
 * count and page-to-event grouping.
 */
final class PdfText {
    public static function analyze(string $pdf_path): array {
        $page_count = self::page_count($pdf_path);
        $pages = self::pages($pdf_path, $page_count);
        $groups = self::groups($pages);
        $assigned = [];
        foreach ($groups as $group) {
            $assigned = array_merge($assigned, $group['page_numbers']);
        }
        $assigned = array_values(array_unique(array_map('intval', $assigned)));
        sort($assigned, SORT_NUMERIC);

        return [
            'page_count' => $page_count,
            'pages' => $pages,
            'groups' => $groups,
            'assigned_pages' => $assigned,
            'unassigned_pages' => $page_count > 0
                ? array_values(array_diff(range(1, $page_count), $assigned))
                : [],
        ];
    }

    public static function page_count(string $pdf_path): int {
        if (!is_readable($pdf_path)) {
            return 0;
        }

        $pdfinfo = self::command_path('pdfinfo');
        if ($pdfinfo !== '') {
            $output = [];
            $code = 1;
            exec(escapeshellarg($pdfinfo) . ' ' . escapeshellarg($pdf_path) . ' 2>&1', $output, $code);
            if ($code === 0 && preg_match('/^Pages:\s*(\d+)/mi', implode("\n", $output), $match)) {
                return max(0, (int) $match[1]);
            }
        }

        if (class_exists(\setasign\Fpdi\Fpdi::class)) {
            try {
                $pdf = new \setasign\Fpdi\Fpdi();
                return max(0, (int) $pdf->setSourceFile($pdf_path));
            } catch (\Throwable $e) {
                Logger::log('STEP', 'FPDI could not determine PDF page count', ['error' => $e->getMessage()]);
            }
        }

        $bytes = file_get_contents($pdf_path);
        if ($bytes === false) {
            return 0;
        }
        if (preg_match_all('/\/Type\s*\/Page(?!s)\b/', $bytes, $matches)) {
            return count($matches[0]);
        }
        return 0;
    }

    /**
     * @return array<int,array{page_number:int,text:string,title_guess:string,date:string,venue:string,fingerprint:string}>
     */
    public static function pages(string $pdf_path, int $page_count = 0): array {
        if (!is_readable($pdf_path)) {
            return [];
        }
        $page_count = $page_count > 0 ? $page_count : self::page_count($pdf_path);
        if ($page_count <= 0) {
            return [];
        }

        $pdftotext = self::command_path('pdftotext');
        if ($pdftotext !== '') {
            $pages = self::pages_with_pdftotext($pdf_path, $page_count, $pdftotext);
            if (count($pages) === $page_count) {
                return $pages;
            }
        }

        return self::pages_with_php($pdf_path, $page_count);
    }

    /**
     * @param array<int,array{page_number:int,text:string,title_guess:string,date:string,venue:string,fingerprint:string}> $pages
     * @return list<array{title_guess:string,date:string,venue:string,page_numbers:list<int>,ticket_count:int,fingerprint:string}>
     */
    public static function groups(array $pages): array {
        $groups = [];
        foreach ($pages as $page) {
            if (empty($page['fingerprint']) || empty($page['date'])) {
                Logger::log('FAIL', 'PDF ticket page could not be grouped', [
                    'page' => (int) ($page['page_number'] ?? 0),
                    'has_date' => !empty($page['date']),
                    'has_fingerprint' => !empty($page['fingerprint']),
                ]);
                continue;
            }
            $key = $page['fingerprint'] . '|' . $page['date'];
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'title_guess' => $page['title_guess'],
                    'date' => $page['date'],
                    'venue' => $page['venue'],
                    'page_numbers' => [],
                    'ticket_count' => 0,
                    'fingerprint' => $page['fingerprint'],
                ];
            }
            $groups[$key]['page_numbers'][] = (int) $page['page_number'];
        }

        foreach ($groups as &$group) {
            $group['page_numbers'] = array_values(array_unique($group['page_numbers']));
            sort($group['page_numbers'], SORT_NUMERIC);
            $group['ticket_count'] = count($group['page_numbers']);
        }
        unset($group);

        return array_values($groups);
    }

    private static function pages_with_pdftotext(string $pdf_path, int $page_count, string $binary): array {
        $pages = [];
        for ($page = 1; $page <= $page_count; $page++) {
            $output = [];
            $code = 1;
            $command = escapeshellarg($binary) . ' -f ' . $page . ' -l ' . $page . ' -layout '
                . escapeshellarg($pdf_path) . ' - 2>/dev/null';
            exec($command, $output, $code);
            if ($code !== 0) {
                return [];
            }
            $text = trim(implode("\n", $output));
            if ($text === '') {
                return [];
            }
            $pages[$page] = self::page_record($page, $text);
        }
        return $pages;
    }

    private static function pages_with_php(string $pdf_path, int $page_count): array {
        $bytes = file_get_contents($pdf_path);
        if ($bytes === false) {
            return [];
        }
        $objects = self::pdf_objects($bytes);
        $page_objects = self::ordered_page_objects($objects);
        if (!$page_objects) {
            return [];
        }

        $pages = [];
        foreach (array_slice($page_objects, 0, $page_count) as $index => $page_object) {
            $content_ids = self::content_object_ids($objects[$page_object] ?? '');
            $runs = [];
            foreach ($content_ids as $content_id) {
                $stream = self::decode_stream($objects[$content_id] ?? '');
                if ($stream !== '') {
                    $runs = array_merge($runs, self::text_runs($stream));
                }
            }
            $text = self::join_runs($runs);
            if ($text !== '') {
                $page = $index + 1;
                $pages[$page] = self::page_record($page, $text);
            }
        }
        return $pages;
    }

    private static function page_record(int $page, string $text): array {
        if (!preg_match('//u', $text)) {
            if (function_exists('mb_convert_encoding')) {
                $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
            } elseif (function_exists('iconv')) {
                $converted = iconv('Windows-1252', 'UTF-8//IGNORE', $text);
                if (is_string($converted)) {
                    $text = $converted;
                }
            }
        }
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?: trim($text);
        $date = self::extract_date($text);
        $title = self::extract_title($text);
        $venue = self::extract_venue($text);

        $fingerprint = preg_replace('/TICKET\s*\d+\s*OF\s*\d+/iu', '', $text) ?: $text;
        $fingerprint = preg_replace('/\d+/u', '', $fingerprint) ?: $fingerprint;
        $uppercase = function_exists('mb_strtoupper') ? mb_strtoupper($fingerprint, 'UTF-8') : strtoupper($fingerprint);
        $fingerprint = preg_replace('/\s+/u', '', $uppercase) ?: $uppercase;

        return [
            'page_number' => $page,
            'text' => $text,
            'title_guess' => $title,
            'date' => $date,
            'venue' => $venue,
            'fingerprint' => md5($fingerprint),
        ];
    }

    private static function extract_title(string $text): string {
        $candidate = preg_split('/Guest\s*\(/iu', $text, 2)[0] ?? '';
        $candidate = preg_replace('/\s+/u', ' ', trim($candidate)) ?: trim($candidate);
        return sanitize_text_field($candidate);
    }

    private static function extract_venue(string $text): string {
        if (preg_match('/VENUE\s*\|?\s*(.+?)\s*IMPORTANT\s+INSTRUCTIONS/iu', $text, $match)) {
            return sanitize_text_field(trim($match[1], " |\t\n\r\0\x0B"));
        }
        if (preg_match('/VENUE\s+(.+?)\s+IMPORTANT/iu', $text, $match)) {
            return sanitize_text_field(trim($match[1], " |\t\n\r\0\x0B"));
        }
        return '';
    }

    private static function extract_date(string $text): string {
        if (!preg_match('/(?<!\d)(\d{2})\/(\d{2})\/(\d{2}|\d{4})(?!\d)/', $text, $match)) {
            return '';
        }
        $year = (int) $match[3];
        if ($year < 100) {
            $year += 2000;
        }
        if (!checkdate((int) $match[2], (int) $match[1], $year)) {
            return '';
        }
        return sprintf('%04d-%02d-%02d', $year, (int) $match[2], (int) $match[1]);
    }

    private static function pdf_objects(string $bytes): array {
        $objects = [];
        if (preg_match_all('/(\d+)\s+\d+\s+obj\b(.*?)endobj/s', $bytes, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $objects[(int) $match[1]] = $match[2];
            }
        }
        return $objects;
    }

    private static function ordered_page_objects(array $objects): array {
        foreach ($objects as $body) {
            if (preg_match('/\/Type\s*\/Pages\b/', $body) && preg_match('/\/Kids\s*\[(.*?)\]/s', $body, $match)) {
                preg_match_all('/(\d+)\s+\d+\s+R/', $match[1], $references);
                if (!empty($references[1])) {
                    return array_values(array_map('intval', $references[1]));
                }
            }
        }
        $pages = [];
        foreach ($objects as $id => $body) {
            if (preg_match('/\/Type\s*\/Page(?!s)\b/', $body)) {
                $pages[] = (int) $id;
            }
        }
        sort($pages, SORT_NUMERIC);
        return $pages;
    }

    private static function content_object_ids(string $page_object): array {
        if (preg_match('/\/Contents\s*\[(.*?)\]/s', $page_object, $match)) {
            preg_match_all('/(\d+)\s+\d+\s+R/', $match[1], $references);
            return array_values(array_map('intval', $references[1] ?? []));
        }
        if (preg_match('/\/Contents\s+(\d+)\s+\d+\s+R/', $page_object, $match)) {
            return [(int) $match[1]];
        }
        return [];
    }

    private static function decode_stream(string $object): string {
        $start = strpos($object, 'stream');
        $end = strpos($object, 'endstream');
        if ($start === false || $end === false || $end <= $start) {
            return '';
        }
        $start += 6;
        if (substr($object, $start, 2) === "\r\n") {
            $start += 2;
        } elseif (in_array(substr($object, $start, 1), ["\r", "\n"], true)) {
            $start++;
        }
        $stream = substr($object, $start, $end - $start);
        if (str_contains(substr($object, 0, $start), '/FlateDecode')) {
            $decoded = @gzuncompress($stream);
            return is_string($decoded) ? $decoded : '';
        }
        return $stream;
    }

    private static function text_runs(string $stream): array {
        $runs = [];
        if (!preg_match_all('/\((?:\\\\.|[^\\\\()])*\)/s', $stream, $matches)) {
            return [];
        }
        foreach ($matches[0] as $literal) {
            $runs[] = self::decode_pdf_literal(substr($literal, 1, -1));
        }
        return $runs;
    }

    private static function decode_pdf_literal(string $value): string {
        $value = preg_replace_callback('/\\\\([0-7]{1,3})/', static fn($match) => chr(octdec($match[1])), $value) ?? $value;
        return strtr($value, [
            '\\n' => "\n",
            '\\r' => "\r",
            '\\t' => "\t",
            '\\b' => "\x08",
            '\\f' => "\x0C",
            '\\(' => '(',
            '\\)' => ')',
            '\\\\' => '\\',
        ]);
    }

    private static function join_runs(array $runs): string {
        $deduplicated = [];
        foreach ($runs as $run) {
            if ($run === '') {
                continue;
            }
            if (!$deduplicated || end($deduplicated) !== $run) {
                $deduplicated[] = $run;
            }
        }
        return implode('', $deduplicated);
    }

    private static function command_path(string $command): string {
        if (!function_exists('exec')) {
            return '';
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (in_array('exec', $disabled, true)) {
            return '';
        }
        $output = [];
        $code = 1;
        exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null', $output, $code);
        return $code === 0 && !empty($output[0]) ? trim($output[0]) : '';
    }
}
