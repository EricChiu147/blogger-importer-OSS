<?php
/**
 * Utility Functions for Blogger Import Open Source
 *
 * This file contains helper functions used throughout the plugin.
 *
 * @package Blogger_Import_OpenSource
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Format a date string from Blogger to WordPress format
 *
 * @param string $date_string Date string from Blogger
 * @return string             Formatted date for WordPress
 */
function bio_format_date($date_string) {
    $date = new DateTime($date_string);
    return $date->format('Y-m-d H:i:s');
}

/**
 * Get a temporary file path
 *
 * @param string $prefix File prefix
 * @param string $ext    File extension
 * @return string        Full path to temporary file
 */
function bio_get_temp_file_path($prefix = 'bio', $ext = 'tmp') {
    // Ensure temp directory exists
    if (!file_exists(BIO_TEMP_DIR)) {
        wp_mkdir_p(BIO_TEMP_DIR);
    }
    
    return BIO_TEMP_DIR . $prefix . '-' . uniqid() . '.' . $ext;
}

/**
 * Clean up temporary files
 *
 * @param string $file_path Single file path to delete, or empty to delete all
 * @return bool             Success status
 */
function bio_cleanup_temp_files($file_path = '') {
    if (!empty($file_path) && file_exists($file_path)) {
        return @unlink($file_path);
    }
    
    // Delete all files in temp directory
    $files = glob(BIO_TEMP_DIR . '*.{tmp,xml}', GLOB_BRACE);
    
    $success = true;
    foreach ($files as $file) {
        if (is_file($file) && !@unlink($file)) {
            $success = false;
        }
    }
    
    return $success;
}

/**
 * Check if a URL is valid
 *
 * @param string $url URL to check
 * @return bool       True if valid
 */
function bio_is_valid_url($url) {
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}

/**
 * Get file extension from URL
 *
 * @param string $url URL
 * @return string     File extension
 */
function bio_get_url_extension($url) {
    $path_parts = pathinfo(parse_url($url, PHP_URL_PATH));
    return isset($path_parts['extension']) ? strtolower($path_parts['extension']) : '';
}

/**
 * Sanitize filename
 *
 * @param string $filename Filename
 * @return string          Sanitized filename
 */
function bio_sanitize_filename($filename) {
    // Fix encoding issues first
    $filename = bio_fix_encoding($filename);
    
    // Use WordPress's sanitize_file_name which better handles international characters
    $filename = sanitize_file_name($filename);
    
    // Ensure it's not empty
    if (empty($filename)) {
        $filename = 'file-' . uniqid();
    }
    
    return $filename;
}

/**
 * Generate a unique slug
 *
 * @param string $title     Post title
 * @param string $post_type Post type
 * @return string           Unique slug
 */
function bio_generate_unique_slug($title, $post_type = 'post') {
    // Fix encoding issues in title before generating slug
    $title = bio_fix_encoding($title);
    
    $slug = sanitize_title($title);
    
    // Check if slug exists
    $check_sql = $GLOBALS['wpdb']->prepare(
        "SELECT post_name FROM {$GLOBALS['wpdb']->posts} WHERE post_name = %s AND post_type = %s",
        $slug,
        $post_type
    );
    
    $post_name_check = $GLOBALS['wpdb']->get_var($check_sql);
    
    if (!$post_name_check) {
        return $slug;
    }
    
    // Slug exists, generate a unique one
    $suffix = 2;
    do {
        $alt_slug = $slug . '-' . $suffix;
        $check_sql = $GLOBALS['wpdb']->prepare(
            "SELECT post_name FROM {$GLOBALS['wpdb']->posts} WHERE post_name = %s AND post_type = %s",
            $alt_slug,
            $post_type
        );
        $post_name_check = $GLOBALS['wpdb']->get_var($check_sql);
        $suffix++;
    } while ($post_name_check);
    
    return $alt_slug;
}

/**
 * Get maximum upload file size
 *
 * @return int Upload size in bytes
 */
function bio_get_max_upload_size() {
    $max_upload = (int) (ini_get('upload_max_filesize'));
    $max_post = (int) (ini_get('post_max_size'));
    $memory_limit = (int) (ini_get('memory_limit'));
    
    // Convert to bytes
    $max_upload = $max_upload * 1024 * 1024;
    $max_post = $max_post * 1024 * 1024;
    $memory_limit = $memory_limit * 1024 * 1024;
    
    // Return the smallest of the three
    return min($max_upload, $max_post, $memory_limit);
}

/**
 * Format file size for display
 *
 * @param int $bytes File size in bytes
 * @return string    Formatted file size
 */
function bio_format_size($bytes) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    
    $bytes /= (1 << (10 * $pow));
    
    return round($bytes, 2) . ' ' . $units[$pow];
}

/**
 * Check if the current user can perform import operations
 *
 * @return bool True if user can import
 */
function bio_current_user_can_import() {
    return current_user_can('import');
}

/**
 * Sanitize array recursively
 *
 * @param array $array Array to sanitize
 * @return array       Sanitized array
 */
function bio_sanitize_array($array) {
    foreach ($array as $key => $value) {
        if (is_array($value)) {
            $array[$key] = bio_sanitize_array($value);
        } else {
            // Fix encoding before sanitizing
            $value = bio_fix_encoding($value);
            $array[$key] = sanitize_text_field($value);
        }
    }
    
    return $array;
}

/**
 * Fix encoding issues in text, especially for Chinese characters
 * This is a centralized utility function for fixing encoding
 *
 * @param string $text Text that may have encoding issues
 * @return string      Properly encoded text
 */
function bio_fix_encoding($text) {
    // Skip if not a string
    if (!is_string($text)) {
        return $text;
    }
    
    // Fix Unicode sequences like u7ffb (Chinese characters without backslash)
    if (preg_match('/u[0-9a-fA-F]{4}/', $text)) {
        $text = preg_replace_callback('/u([0-9a-fA-F]{4})/', function($matches) {
            try {
                // Method 1: Use mb_convert_encoding with pack
                $hex = $matches[1];
                $char = mb_convert_encoding(pack('H*', $hex), 'UTF-8', 'UCS-2BE');
                return $char;
            } catch (Exception $e) {
                // Fallback to method 2
                try {
                    // Method 2: Try json_decode with proper escape sequence
                    $escaped = '"\u' . $matches[1] . '"';
                    $char = json_decode($escaped);
                    if ($char !== null) {
                        return $char;
                    }
                } catch (Exception $e) {
                    // Ignore and return original
                }
                
                // Return original if both methods fail
                return $matches[0];
            }
        }, $text);
    }
    
    return $text;
}

/**
 * Check if a string contains Chinese characters
 *
 * @param string $text Text to check
 * @return bool        True if contains Chinese characters
 */
function bio_has_chinese_characters($text) {
    // Check for common Chinese character Unicode ranges
    return preg_match('/[\x{4e00}-\x{9fff}\x{3400}-\x{4dbf}\x{20000}-\x{2a6df}\x{2a700}-\x{2b73f}\x{2b740}-\x{2b81f}\x{2b820}-\x{2ceaf}\x{f900}-\x{faff}\x{2f800}-\x{2fa1f}]/u', $text);
}