<?php
/**
 * Database Handler for Blogger Import Open Source
 *
 * This file handles database operations for the import process,
 * including storing mappings and import progress.
 *
 * @package Blogger_Import_OpenSource
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Class responsible for database operations
 */
class BIO_DB_Handler {
    /**
     * Store import progress in a transient
     *
     * @param array $progress Progress data
     * @return bool           Success status
     */
    public static function update_import_progress($progress) {
        return set_transient('bio_import_progress', $progress, 24 * HOUR_IN_SECONDS);
    }
    
    /**
     * Get import progress from transient
     *
     * @return array|false Progress data or false if not found
     */
    public static function get_import_progress() {
        return get_transient('bio_import_progress');
    }
    
    /**
     * Delete import progress transient
     *
     * @return bool Success status
     */
    public static function delete_import_progress() {
        return delete_transient('bio_import_progress');
    }
    
    /**
     * Store a Blogger to WordPress post ID mapping
     *
     * @param string $blogger_id  Original Blogger post ID
     * @param int    $wp_post_id  WordPress post ID
     * @param string $blogger_url Original Blogger URL
     * @return bool               Success status
     */
    public static function store_post_mapping($blogger_id, $wp_post_id, $blogger_url) {
        update_post_meta($wp_post_id, '_bio_blogger_id', $blogger_id);
        update_post_meta($wp_post_id, '_bio_blogger_url', $blogger_url);
        
        // Also store in options table for lookup by Blogger ID
        $mappings = get_option('bio_post_mappings', array());
        $mappings[$blogger_id] = array(
            'wp_id' => $wp_post_id,
            'blogger_url' => $blogger_url
        );
        return update_option('bio_post_mappings', $mappings);
    }
    
    /**
     * Get WordPress post ID from Blogger ID
     *
     * @param string $blogger_id Blogger post ID
     * @return int|false         WordPress post ID or false if not found
     */
    public static function get_wp_post_id_from_blogger_id($blogger_id) {
        $mappings = get_option('bio_post_mappings', array());
        if (isset($mappings[$blogger_id])) {
            return $mappings[$blogger_id]['wp_id'];
        }
        
        // Try to find by post meta as fallback
        global $wpdb;
        $wp_post_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_bio_blogger_id' AND meta_value = %s LIMIT 1",
                $blogger_id
            )
        );
        
        return $wp_post_id ? (int) $wp_post_id : false;
    }
    
    /**
     * Store a Blogger to WordPress comment ID mapping
     *
     * @param string $blogger_id     Original Blogger comment ID
     * @param int    $wp_comment_id  WordPress comment ID
     * @return bool                  Success status
     */
    public static function store_comment_mapping($blogger_id, $wp_comment_id) {
        update_comment_meta($wp_comment_id, '_bio_blogger_comment_id', $blogger_id);
        
        // Also store in options table for lookup
        $mappings = get_option('bio_comment_mappings', array());
        $mappings[$blogger_id] = $wp_comment_id;
        return update_option('bio_comment_mappings', $mappings);
    }
    
    /**
     * Get WordPress comment ID from Blogger ID
     *
     * @param string $blogger_id Blogger comment ID
     * @return int|false         WordPress comment ID or false if not found
     */
    public static function get_wp_comment_id_from_blogger_id($blogger_id) {
        $mappings = get_option('bio_comment_mappings', array());
        if (isset($mappings[$blogger_id])) {
            return $mappings[$blogger_id];
        }
        
        // Try to find by comment meta as fallback
        global $wpdb;
        $wp_comment_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT comment_id FROM {$wpdb->commentmeta} WHERE meta_key = '_bio_blogger_comment_id' AND meta_value = %s LIMIT 1",
                $blogger_id
            )
        );
        
        return $wp_comment_id ? (int) $wp_comment_id : false;
    }
    
    /**
     * Get all mappings for export
     *
     * @return array Mappings array
     */
    public static function get_all_mappings() {
        $mappings = array();
        
        // Get post mappings
        global $wpdb;
        $results = $wpdb->get_results(
            "SELECT p.ID, p.post_type, pm1.meta_value as blogger_id, pm2.meta_value as blogger_url 
            FROM {$wpdb->posts} p 
            JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_bio_blogger_id' 
            JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_bio_blogger_url'
            ORDER BY p.ID"
        );
        
        foreach ($results as $row) {
            $wp_url = get_permalink($row->ID);
            $mappings[] = array(
                'blogger_id' => $row->blogger_id,
                'blogger_url' => $row->blogger_url,
                'wp_id' => $row->ID,
                'wp_url' => $wp_url,
                'post_type' => $row->post_type
            );
        }
        
        return $mappings;
    }
    
    /**
     * Update post content with proper encoding
     *
     * @param int    $post_id Post ID to update
     * @param string $content Content with possible encoding issues
     * @return bool           Whether the update was successful
     */
    public static function update_post_content($post_id, $content) {
        // Process content for Chinese character encoding if needed
        $content = self::ensure_proper_encoding($content);
        
        // Update the post with fixed content
        return wp_update_post(
            array(
                'ID' => $post_id,
                'post_content' => $content
            )
        ) !== 0;
    }
    
    /**
     * Update post title with proper encoding
     *
     * @param int    $post_id Post ID to update
     * @param string $title   Title with possible encoding issues
     * @return bool           Whether the update was successful
     */
    public static function update_post_title($post_id, $title) {
        // Process title for Chinese character encoding if needed
        $title = self::ensure_proper_encoding($title);
        
        // Update the post with fixed title
        return wp_update_post(
            array(
                'ID' => $post_id,
                'post_title' => $title
            )
        ) !== 0;
    }
    
    /**
     * Ensure proper encoding for text
     *
     * @param string $text Text that may have encoding issues
     * @return string      Properly encoded text
     */
    public static function ensure_proper_encoding($text) {
        // Check for Unicode escape sequences like u5408u7968 (Chinese characters)
        if (preg_match('/u[0-9a-fA-F]{4}/', $text)) {
            $text = preg_replace_callback('/u([0-9a-fA-F]{4})/', function($matches) {
                return json_decode('"\u' . $matches[1] . '"') ?: $matches[0];
            }, $text);
        }
        
        // Check if we need to convert encoding
        if (function_exists('mb_check_encoding') && !mb_check_encoding($text, 'UTF-8')) {
            // Try to detect and convert encoding
            if (function_exists('mb_detect_encoding')) {
                $encoding = mb_detect_encoding($text, array('UTF-8', 'ISO-8859-1', 'GBK', 'BIG5', 'GB18030'), true);
                if ($encoding && $encoding !== 'UTF-8') {
                    $text = mb_convert_encoding($text, 'UTF-8', $encoding);
                }
            }
        }
        
        return $text;
    }
    
    /**
     * Store import statistics
     *
     * @param array $stats Import statistics
     * @return bool        Success status
     */
    public static function store_import_stats($stats) {
        return update_option('bio_import_stats', $stats);
    }
    
    /**
     * Get import statistics
     *
     * @return array Import statistics
     */
    public static function get_import_stats() {
        return get_option('bio_import_stats', array());
    }
    
    /**
     * Log an error
     *
     * @param string $message Error message
     * @param string $context Error context
     * @return bool           Success status
     */
    public static function log_error($message, $context = '') {
        $errors = get_option('bio_import_errors', array());
        $errors[] = array(
            'message' => $message,
            'context' => $context,
            'time' => current_time('mysql')
        );
        
        // Limit to 100 most recent errors
        if (count($errors) > 100) {
            $errors = array_slice($errors, -100);
        }
        
        return update_option('bio_import_errors', $errors);
    }
    
    /**
     * Get all logged errors
     *
     * @return array Error logs
     */
    public static function get_errors() {
        return get_option('bio_import_errors', array());
    }
    
    /**
     * Clear error logs
     *
     * @return bool Success status
     */
    public static function clear_errors() {
        return delete_option('bio_import_errors');
    }

    /**
     * Save import options
     *
     * @param array $options Import options
     * @return bool Success or failure
     */
    public static function save_import_options($options) {
        return update_option('bio_import_options', $options);
    }

    /**
     * Get import options
     *
     * @return array Import options
     */
    public static function get_import_options() {
        $default_options = array(
            'skip_media' => false,
            'use_current_user' => true,
            'create_redirects' => false,
            'redirect_type' => 'htaccess',
            'author_override' => 0
        );
        
        $options = get_option('bio_import_options', array());
        
        return wp_parse_args($options, $default_options);
    }
}