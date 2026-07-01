<?php
/**
 * WebP conversion engine.
 *
 * Handles single-image and batch-thumbnail conversion
 * with automatic GD / Imagick detection.
 *
 * @package AHM_Core
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class AHM_WebP_Converter
{
    /*--------------------------------------------------------------
     * Server Capability
     *------------------------------------------------------------*/

    public static function server_supports_webp(): bool
    {
        if (function_exists('imagewebp') && function_exists('imagecreatefromjpeg')) {
            return true;
        }

        if (class_exists('Imagick')) {
            return ! empty(\Imagick::queryFormats('WEBP'));
        }

        return false;
    }

    /**
     * @return 'imagick'|'gd'|null
     */
    public static function detect_library(): ?string
    {
        if (class_exists('Imagick') && ! empty(\Imagick::queryFormats('WEBP'))) {
            return 'imagick';
        }

        if (function_exists('imagewebp')) {
            return 'gd';
        }

        return null;
    }

    /*--------------------------------------------------------------
     * Public Conversion API
     *------------------------------------------------------------*/

    /**
     * Convert a single image file to WebP.
     *
     * @return string|false Absolute path to the new .webp file, or false.
     */
    public static function convert(string $source_path, int $quality = 80): string|false
    {
        if (! file_exists($source_path) || ! is_readable($source_path)) {
            return false;
        }

        $mime = wp_check_filetype($source_path)['type'] ?? '';

        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/gif'], true)) {
            return false;
        }

        $quality = max(1, min(100, $quality));
        $dest    = preg_replace('/\.(jpe?g|png|gif)$/i', '.webp', $source_path);

        if ($dest === $source_path) {
            $dest = $source_path . '.webp';
        }

        $library = self::detect_library();

        $success = match ($library) {
            'imagick' => self::convert_imagick($source_path, $dest, $mime, $quality),
            'gd'      => self::convert_gd($source_path, $dest, $mime, $quality),
            default   => false,
        };

        return $success ? $dest : false;
    }

    /**
     * Hook: wp_generate_attachment_metadata — auto-convert on upload.
     *
     * @param  array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public static function convert_attachment(array $metadata, int $attachment_id): array
    {
        $settings = get_option('ahm_webp_settings', []);

        if (empty($settings['auto_convert'])) {
            return $metadata;
        }

        $quality = (int) ($settings['quality'] ?? 80);
        $delete  = ! empty($settings['delete_original']);
        $base    = trailingslashit(wp_get_upload_dir()['basedir']);

        // Full-size.
        if (! empty($metadata['file'])) {
            $full_path = $base . $metadata['file'];
            $webp_path = self::convert($full_path, $quality);

            if ($webp_path) {
                update_post_meta($attachment_id, '_ahm_webp_file', str_replace($base, '', $webp_path));

                if ($delete) {
                    self::maybe_delete_original($full_path, $attachment_id);
                }
            }
        }

        // Thumbnails.
        if (! empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            $sub_dir = dirname($metadata['file']);

            foreach ($metadata['sizes'] as $size_data) {
                $thumb_path = $base . $sub_dir . '/' . $size_data['file'];
                $webp_thumb = self::convert($thumb_path, $quality);

                if ($webp_thumb && $delete) {
                    self::maybe_delete_original($thumb_path);
                }
            }
        }

        return $metadata;
    }

    /**
     * Bulk-convert a single attachment by ID.
     *
     * @return array{success: bool, message: string, file?: string}
     */
    public static function bulk_convert_single(int $attachment_id): array
    {
        $file = get_attached_file($attachment_id);

        if (! $file || ! file_exists($file)) {
            return ['success' => false, 'message' => 'File not found.'];
        }

        $mime = get_post_mime_type($attachment_id);

        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/gif'], true)) {
            return ['success' => false, 'message' => 'Unsupported MIME type: ' . $mime];
        }

        // Already converted?
        $existing = get_post_meta($attachment_id, '_ahm_webp_file', true);
        if ($existing) {
            $check = trailingslashit(wp_get_upload_dir()['basedir']) . $existing;
            if (file_exists($check)) {
                return ['success' => true, 'message' => 'Already converted.', 'file' => $existing];
            }
        }

        $settings = get_option('ahm_webp_settings', []);
        $quality  = (int) ($settings['quality'] ?? 80);
        $delete   = ! empty($settings['delete_original']);
        $base     = trailingslashit(wp_get_upload_dir()['basedir']);

        $webp = self::convert($file, $quality);

        if (! $webp) {
            return ['success' => false, 'message' => 'Conversion failed.'];
        }

        update_post_meta($attachment_id, '_ahm_webp_file', str_replace($base, '', $webp));

        if ($delete) {
            self::maybe_delete_original($file, $attachment_id);
        }

        // Thumbnails.
        $metadata = wp_get_attachment_metadata($attachment_id);

        if (! empty($metadata['sizes']) && ! empty($metadata['file'])) {
            $sub_dir = dirname($metadata['file']);

            foreach ($metadata['sizes'] as $size_data) {
                $thumb = $base . $sub_dir . '/' . $size_data['file'];
                $webp_thumb = self::convert($thumb, $quality);

                if ($webp_thumb && $delete) {
                    self::maybe_delete_original($thumb);
                }
            }
        }

        return [
            'success' => true,
            'message' => 'Converted successfully.',
            'file'    => str_replace($base, '', $webp),
        ];
    }

    /*--------------------------------------------------------------
     * Private Engines
     *------------------------------------------------------------*/

    private static function convert_gd(string $src, string $dest, string $mime, int $quality): bool
    {
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($src),
            'image/png'  => @imagecreatefrompng($src),
            'image/gif'  => @imagecreatefromgif($src),
            default      => false,
        };

        if (! $image) {
            return false;
        }

        if (in_array($mime, ['image/png', 'image/gif'], true)) {
            imagepalettetotruecolor($image);
            imagealphablending($image, true);
            imagesavealpha($image, true);
        }

        $result = imagewebp($image, $dest, $quality);
        imagedestroy($image);

        return $result;
    }

    private static function convert_imagick(string $src, string $dest, string $mime, int $quality): bool
    {
        try {
            $imagick = new \Imagick($src);

            if ($mime === 'image/png') {
                $imagick->setImageAlphaChannel(\Imagick::ALPHACHANNEL_ACTIVATE);
            }

            if ($mime === 'image/gif') {
                $imagick = $imagick->coalesceImages();
                $imagick = $imagick->current(); /** @phpstan-ignore-line */
            }

            $imagick->setImageFormat('webp');
            $imagick->setImageCompressionQuality($quality);
            $imagick->setOption('webp:method', '6');

            $result = $imagick->writeImage($dest);
            $imagick->clear();
            $imagick->destroy();

            return $result;
        } catch (\ImagickException $e) {
            error_log('[AHM Core] Imagick error: ' . $e->getMessage());
            return false;
        }
    }

    /*--------------------------------------------------------------
     * Utility
     *------------------------------------------------------------*/

    private static function maybe_delete_original(string $path, int $attachment_id = 0): void
    {
        if (file_exists($path)) {
            wp_delete_file($path);
        }

        if ($attachment_id > 0) {
            $webp_path = preg_replace('/\.(jpe?g|png|gif)$/i', '.webp', $path);

            if ($webp_path && file_exists($webp_path)) {
                update_attached_file($attachment_id, $webp_path);
                wp_update_post([
                    'ID'             => $attachment_id,
                    'post_mime_type' => 'image/webp',
                ]);
            }
        }
    }

    public static function get_webp_url(int $attachment_id): string|false
    {
        $webp_file = get_post_meta($attachment_id, '_ahm_webp_file', true);

        if (! $webp_file) {
            return false;
        }

        $upload = wp_get_upload_dir();
        $path   = trailingslashit($upload['basedir']) . $webp_file;

        return file_exists($path)
            ? trailingslashit($upload['baseurl']) . $webp_file
            : false;
    }
}
