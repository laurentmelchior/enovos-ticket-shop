<?php
namespace Enovos\TicketShop;

if (!defined('ABSPATH')) {
    exit;
}

final class PdfPackages {
    /** Imagick fallback DPI – keep low to avoid multi‑MB ticket packages. */
    private const IMAGICK_DPI = 150;
    private const IMAGICK_JPEG_QUALITY = 75;
    private const WARN_SIZE_BYTES = 2 * 1024 * 1024;

    public static function private_base_dir(): string {
        $uploads = wp_upload_dir();
        $base = trailingslashit($uploads['basedir']) . 'woocommerce_uploads/enovos-ticket-shop';
        if (!is_dir($base)) {
            wp_mkdir_p($base);
        }
        self::protect_directory($base);
        return $base;
    }

    public static function create_import_dir(string $import_id): string {
        $dir = trailingslashit(self::private_base_dir()) . sanitize_file_name($import_id);
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        self::protect_directory($dir);
        return $dir;
    }

    public static function engine_status(): array {
        $poppler = self::poppler_commands();
        if ($poppler) {
            return [
                'available' => true,
                'engine' => 'poppler',
                'message' => 'Poppler PDF engine is available. Original PDF pages will be preserved without rasterizing (small files).',
            ];
        }
        if (class_exists('Imagick')) {
            try {
                $formats = \Imagick::queryFormats('PDF');
                if ($formats) {
                    return [
                        'available' => true,
                        'engine' => 'imagick',
                        'message' => 'Imagick fallback at ' . self::IMAGICK_DPI . ' DPI (install Poppler for smaller lossless packages).',
                    ];
                }
            } catch (\Throwable $e) {
                return [
                    'available' => false,
                    'engine' => 'imagick',
                    'message' => 'Imagick PDF support check failed: ' . $e->getMessage(),
                ];
            }
        }
        return [
            'available' => false,
            'engine' => 'none',
            'message' => 'No supported PDF engine is available. Install Poppler (pdfseparate + pdfunite) or enable PHP Imagick with PDF support.',
        ];
    }

    public static function create_two_page_pdf(string $source_pdf, array $pages, string $destination) {
        $pages = array_values(array_map('intval', $pages));
        if (count($pages) !== 2 || min($pages) < 1) {
            return new \WP_Error('invalid_ticket_pages', 'Exactly two valid 1-based PDF page numbers are required.');
        }
        if (!is_readable($source_pdf)) {
            return new \WP_Error('source_pdf_missing', 'The source ticket PDF is not readable.');
        }

        $status = self::engine_status();
        if (empty($status['available'])) {
            return new \WP_Error('pdf_engine_unavailable', $status['message']);
        }

        $result = $status['engine'] === 'poppler'
            ? self::create_with_poppler($source_pdf, $pages, $destination)
            : self::create_with_imagick($source_pdf, $pages, $destination);

        if (!is_wp_error($result) && is_readable($destination)) {
            $size = (int) filesize($destination);
            Logger::log('STEP', 'Ticket package PDF written', [
                'engine' => $status['engine'],
                'path' => basename($destination),
                'bytes' => $size,
                'kb' => round($size / 1024, 1),
            ]);
            if ($size > self::WARN_SIZE_BYTES) {
                Logger::log('FAIL', 'Ticket package PDF is larger than 2 MB', [
                    'engine' => $status['engine'],
                    'bytes' => $size,
                    'hint' => $status['engine'] === 'imagick'
                        ? 'Install Poppler (pdfseparate + pdfunite) to keep original page size.'
                        : 'Source ticket pages may already be large images.',
                ]);
            }
        }

        return $result;
    }

    private static function create_with_poppler(string $source_pdf, array $pages, string $destination) {
        $commands = self::poppler_commands();
        if (!$commands) {
            return new \WP_Error('poppler_unavailable', 'Poppler PDF commands are not available.');
        }
        $tmp = trailingslashit(dirname($destination)) . '.tmp-' . wp_generate_password(10, false, false);
        if (!wp_mkdir_p($tmp)) {
            return new \WP_Error('pdf_tmp_failed', 'Temporary ticket PDF directory could not be created.');
        }
        $parts = [];
        try {
            foreach ($pages as $index => $page) {
                $part = trailingslashit($tmp) . 'page-' . ($index + 1) . '.pdf';
                $cmd = escapeshellarg($commands['pdfseparate']) . ' -f ' . (int) $page . ' -l ' . (int) $page . ' ' . escapeshellarg($source_pdf) . ' ' . escapeshellarg($part) . ' 2>&1';
                $output = [];
                $code = 1;
                exec($cmd, $output, $code);
                if ($code !== 0 || !is_file($part)) {
                    return new \WP_Error('pdf_page_extract_failed', 'A ticket page could not be extracted: ' . implode(' ', array_slice($output, -3)));
                }
                $parts[] = $part;
            }
            $cmd = escapeshellarg($commands['pdfunite']) . ' ' . escapeshellarg($parts[0]) . ' ' . escapeshellarg($parts[1]) . ' ' . escapeshellarg($destination) . ' 2>&1';
            $output = [];
            $code = 1;
            exec($cmd, $output, $code);
            if ($code !== 0 || !is_file($destination) || filesize($destination) < 1000) {
                return new \WP_Error('pdf_merge_failed', 'The two ticket pages could not be merged: ' . implode(' ', array_slice($output, -3)));
            }
            @chmod($destination, 0640);
            return $destination;
        } finally {
            foreach ($parts as $part) {
                @unlink($part);
            }
            @rmdir($tmp);
        }
    }

    private static function create_with_imagick(string $source_pdf, array $pages, string $destination) {
        try {
            $output = new \Imagick();
            $output->setResolution(self::IMAGICK_DPI, self::IMAGICK_DPI);
            foreach ($pages as $page) {
                $page_image = new \Imagick();
                $page_image->setResolution(self::IMAGICK_DPI, self::IMAGICK_DPI);
                $page_image->readImage($source_pdf . '[' . ($page - 1) . ']');
                if (method_exists($page_image, 'setImageBackgroundColor')) {
                    $page_image->setImageBackgroundColor('white');
                }
                if (method_exists($page_image, 'mergeImageLayers')) {
                    $page_image = $page_image->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
                }
                // Rasterize → JPEG blob → PDF page so quality settings actually shrink the file.
                $page_image->setImageFormat('jpeg');
                $page_image->setImageCompression(\Imagick::COMPRESSION_JPEG);
                $page_image->setImageCompressionQuality(self::IMAGICK_JPEG_QUALITY);
                if (method_exists($page_image, 'stripImage')) {
                    $page_image->stripImage();
                }
                $jpeg_blob = $page_image->getImageBlob();
                $page_image->clear();
                $page_image->destroy();

                $compressed = new \Imagick();
                $compressed->readImageBlob($jpeg_blob);
                $compressed->setImageFormat('pdf');
                $output->addImage($compressed);
                $compressed->clear();
                $compressed->destroy();
            }
            $ok = $output->writeImages($destination, true);
            $output->clear();
            $output->destroy();
            if (!$ok || !is_file($destination) || filesize($destination) < 1000) {
                return new \WP_Error('pdf_package_write_failed', 'The two-ticket PDF package could not be written.');
            }
            @chmod($destination, 0640);
            return $destination;
        } catch (\Throwable $e) {
            return new \WP_Error('pdf_package_exception', 'Ticket PDF generation failed: ' . $e->getMessage());
        }
    }

    private static function poppler_commands(): array {
        if (!function_exists('exec')) {
            return [];
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (in_array('exec', $disabled, true)) {
            return [];
        }
        $result = [];
        foreach (['pdfseparate', 'pdfunite'] as $binary) {
            $out = [];
            $code = 1;
            @exec('command -v ' . $binary . ' 2>/dev/null', $out, $code);
            if ($code !== 0 || empty($out[0])) {
                return [];
            }
            $result[$binary] = trim($out[0]);
        }
        return $result;
    }

    public static function protect_directory(string $dir): void {
        $htaccess = trailingslashit($dir) . '.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "Require all denied\nDeny from all\n");
        }
        $webconfig = trailingslashit($dir) . 'web.config';
        if (!file_exists($webconfig)) {
            @file_put_contents($webconfig, '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>');
        }
        $index = trailingslashit($dir) . 'index.php';
        if (!file_exists($index)) {
            @file_put_contents($index, "<?php\n// Silence is golden.\n");
        }
    }
}
