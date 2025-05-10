<?php
/**
 * Media Handler for Blogger Import Open Source
 *
 * This file handles downloading and importing media from Blogger posts.
 *
 * @package Blogger_Import_OpenSource
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Class responsible for handling media imports
 */
class BIO_Media_Handler {
    /**
     * Download and import a media file
     *
     * @param string $url      URL of the media file
     * @param int    $post_id  WordPress post ID to attach to
     * @return int|WP_Error    Attachment ID or WP_Error
     */
    public static function import_media($url, $post_id = 0) {
        // Validate URL
        if (!bio_is_valid_url($url)) {
            return new WP_Error('invalid_url', __('Invalid media URL', 'blogger-import-opensource'));
        }
        
        // Check if we've already imported this URL
        $existing_id = self::get_attachment_id_by_url($url);
        if ($existing_id) {
            return $existing_id;
        }
        
        // Include WordPress upload functionality
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        
        // Fix Google image URLs to ensure they have proper extensions
        $url = self::fix_google_image_url($url);
        
        // Get filename from URL
        $filename = basename(parse_url($url, PHP_URL_PATH));
        
        // Sanitize filename
        $filename = bio_sanitize_filename($filename);
        
        // Handle Google URLs without extensions
        $is_google_url = self::is_google_url($url);
        $file_ext = pathinfo($filename, PATHINFO_EXTENSION);
        
        if ($is_google_url && empty($file_ext)) {
            // Determine content type from headers
            $headers = wp_remote_head($url);
            if (!is_wp_error($headers)) {
                $content_type = wp_remote_retrieve_header($headers, 'content-type');
                
                // Add extension based on content type
                $extension = self::get_extension_from_content_type($content_type);
                if (!empty($extension)) {
                    $filename = sanitize_file_name(md5($url) . '.' . $extension);
                }
            } else {
                // If we can't determine content type, default to jpg
                $filename = sanitize_file_name(md5($url) . '.jpg');
            }
        } elseif (empty($file_ext)) {
            // For non-Google URLs, try to determine extension
            $file_ext = bio_get_url_extension($url);
            if (!empty($file_ext)) {
                $filename .= '.' . $file_ext;
            }
        }
        
        // Set timeout for download
        add_filter('http_request_timeout', function() { return 60; }); // 60 seconds
        
        // Download the file with retry
        $tmp_file = bio_get_temp_file_path('bio_media', pathinfo($filename, PATHINFO_EXTENSION));
        
        $operation_id = 'download_media_' . md5($url);
        $downloaded = BIO_Error_Handler::execute_with_retry(
            array('BIO_Media_Handler', 'download_url_to_file'),
            array($url, $tmp_file),
            $operation_id,
            'Downloading media: ' . $url
        );
        
        if (is_wp_error($downloaded)) {
            BIO_Error_Handler::log_error(
                sprintf('Failed to download media from %s: %s', $url, $downloaded->get_error_message()),
                'media_import'
            );
            @unlink($tmp_file); // Clean up
            return $downloaded;
        }
        
        if (!file_exists($tmp_file)) {
            BIO_Error_Handler::log_error(
                sprintf('Downloaded file not found: %s', $tmp_file),
                'media_import'
            );
            return new WP_Error('download_failed', __('Downloaded file not found', 'blogger-import-opensource'));
        }
        
        // Verify and fix file extension based on actual content
        $tmp_file = self::verify_and_fix_file_extension($tmp_file, $filename);
        if (is_wp_error($tmp_file)) {
            return $tmp_file;
        }
        
        // Update filename after potential extension fix
        $filename = basename($tmp_file);
        
        // Prepare file data
        $file_array = array(
            'name'     => $filename,
            'tmp_name' => $tmp_file,
            'error'    => 0,
            'size'     => filesize($tmp_file),
        );
        
        // Insert attachment with retry
        $operation_id = 'media_sideload_' . md5($url);
        $attachment_id = BIO_Error_Handler::execute_with_retry(
            array('BIO_Media_Handler', 'media_sideload'),
            array($file_array, $post_id, $url),
            $operation_id,
            'Importing media: ' . $filename
        );
        
        // Clean up temp file
        @unlink($tmp_file);
        
        if (is_wp_error($attachment_id)) {
            BIO_Error_Handler::log_error(
                sprintf('Failed to import media %s: %s', $filename, $attachment_id->get_error_message()),
                'media_import'
            );
            return $attachment_id;
        }
        
        // Store the original URL in attachment metadata
        update_post_meta($attachment_id, '_bio_original_url', $url);
        
        do_action('bio_after_import_media', $attachment_id, $url, $post_id);
        
        return $attachment_id;
    }
    
    /**
     * Find and import all media in a post
     *
     * @param int   $post_id WordPress post ID
     * @param array $urls    Array of media URLs
     * @param bool  $update_content Whether to update post content with new URLs
     * @return array         Results
     */
    public static function import_post_media($post_id, $urls = array(), $update_content = true) {
        if (empty($post_id)) {
            return array(
                'total' => 0,
                'success' => 0,
                'failed' => 0,
                'skipped' => 0,
            );
        }
        
        // If no URLs provided, extract from post content
        if (empty($urls)) {
            $post = get_post($post_id);
            if (!$post) {
                return array(
                    'total' => 0,
                    'success' => 0,
                    'failed' => 0,
                    'skipped' => 0,
                );
            }
            
            preg_match_all('/<img[^>]+src="([^"]+)"[^>]*>/i', $post->post_content, $matches);
            if (isset($matches[1])) {
                $urls = array_merge($urls, $matches[1]);
            }
        }
        
        // Remove duplicates and filter URLs
        $urls = array_unique($urls);
        $urls = array_filter($urls, function($url) {
            return bio_is_valid_url($url);
        });
        
        $results = array(
            'total' => count($urls),
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
            'url_mapping' => array()
        );
        
        // No media to import
        if (empty($urls)) {
            return $results;
        }
        
        // Import each media URL
        $current = 0;
        $total = count($urls);
        
        foreach ($urls as $url) {
            $current++;
            
            // Update progress
            $progress = array(
                'step' => 'import_media',
                'current' => $current,
                'total' => $total,
                'percentage' => round(($current / $total) * 100),
                'message' => sprintf(__('Importing media %d of %d: %s', 'blogger-import-opensource'), 
                                   $current, $total, basename(parse_url($url, PHP_URL_PATH)))
            );
            BIO_DB_Handler::update_import_progress($progress);
            
            // Skip if already in media library
            $existing_id = self::get_attachment_id_by_url($url);
            if ($existing_id) {
                $attachment_url = wp_get_attachment_url($existing_id);
                $results['url_mapping'][$url] = $attachment_url;
                $results['skipped']++;
                continue;
            }
            
            // Import the media
            $attachment_id = self::import_media($url, $post_id);
            
            if (is_wp_error($attachment_id)) {
                $results['failed']++;
            } else {
                $attachment_url = wp_get_attachment_url($attachment_id);
                $results['url_mapping'][$url] = $attachment_url;
                $results['success']++;
            }
            
            // Give the server a small break
            usleep(100000); // 0.1 seconds
        }
        
        // Update post content with new URLs if requested
        if ($update_content && !empty($results['url_mapping'])) {
            $post = get_post($post_id);
            if ($post) {
                $content = $post->post_content;
                
                // Replace URLs in content
                foreach ($results['url_mapping'] as $old_url => $new_url) {
                    $content = str_replace($old_url, $new_url, $content);
                }
                
                // Update the post
                wp_update_post(array(
                    'ID' => $post_id,
                    'post_content' => $content
                ));
            }
        }
        
        return $results;
    }
    
    /**
     * Import media for multiple posts
     *
     * @param array $post_media_map Post ID => array of media URLs
     * @return array                Results
     */
    public static function import_all_media($post_media_map) {
        $overall_results = array(
            'total' => 0,
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
        );
        
        $current_post = 0;
        $total_posts = count($post_media_map);
        
        foreach ($post_media_map as $post_id => $urls) {
            $current_post++;
            
            // Update progress
            $progress = array(
                'step' => 'import_all_media',
                'current' => $current_post,
                'total' => $total_posts,
                'percentage' => round(($current_post / $total_posts) * 100),
                'message' => sprintf(__('Importing media for post %d of %d', 'blogger-import-opensource'), 
                                   $current_post, $total_posts)
            );
            BIO_DB_Handler::update_import_progress($progress);
            
            // Import media for this post
            $post_results = self::import_post_media($post_id, $urls, true);
            
            // Update overall results
            $overall_results['total'] += $post_results['total'];
            $overall_results['success'] += $post_results['success'];
            $overall_results['failed'] += $post_results['failed'];
            $overall_results['skipped'] += $post_results['skipped'];
        }
        
        return $overall_results;
    }
    
    /**
     * Helper to find attachment by URL
     *
     * @param string $url URL to check
     * @return int|false   Attachment ID or false if not found
     */
    public static function get_attachment_id_by_url($url) {
        global $wpdb;
        
        // First check if we've stored the original URL
        $attachment_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_bio_original_url' AND meta_value = %s LIMIT 1",
                $url
            )
        );
        
        if ($attachment_id) {
            return (int) $attachment_id;
        }
        
        // Try to match by attachment URL
        $upload_dir = wp_upload_dir();
        $base_url = $upload_dir['baseurl'];
        
        // If using relative URL, prepend base URL
        if (strpos($url, 'http') !== 0 && strpos($url, '//') !== 0) {
            $url = $base_url . '/' . ltrim($url, '/');
        }
        
        // Try to get attachment by URL
        $attachment = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE guid = %s AND post_type = 'attachment'",
                $url
            )
        );
        
        if (!empty($attachment)) {
            return (int) $attachment[0];
        }
        
        // Try to match by filename
        $url_filename = basename(parse_url($url, PHP_URL_PATH));
        
        if (empty($url_filename)) {
            return false;
        }
        
        $attachments = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, guid FROM {$wpdb->posts} WHERE post_type = 'attachment'"
            )
        );
        
        foreach ($attachments as $attachment) {
            $attachment_filename = basename($attachment->guid);
            if ($attachment_filename === $url_filename) {
                return (int) $attachment->ID;
            }
        }
        
        return false;
    }
    
    /**
     * Download URL to file with extra safety checks
     *
     * @param string $url      URL to download
     * @param string $file_path Path to save file
     * @return bool|WP_Error   True on success or WP_Error
     */
    public static function download_url_to_file($url, $file_path) {
        // Make sure the URL is properly encoded
        $url = str_replace(' ', '%20', $url);
        
        // Use WordPress download_url function
        $tmp_file = download_url($url);
        
        if (is_wp_error($tmp_file)) {
            return $tmp_file;
        }
        
        // Move the file to our destination
        $result = @copy($tmp_file, $file_path);
        @unlink($tmp_file); // Clean up temp file
        
        if (!$result) {
            return new WP_Error(
                'download_failed', 
                sprintf(__('Failed to save downloaded file to %s', 'blogger-import-opensource'), $file_path)
            );
        }
        
        return true;
    }
    
    /**
     * Custom media sideload implementation
     *
     * @param array  $file_array File data array
     * @param int    $post_id    Post ID to attach to
     * @param string $source_url Original URL
     * @return int|WP_Error      Attachment ID or WP_Error
     */
    public static function media_sideload($file_array, $post_id = 0, $source_url = '') {
        $id = media_handle_sideload($file_array, $post_id);
        
        if (is_wp_error($id)) {
            @unlink($file_array['tmp_name']); // Clean up
            return $id;
        }
        
        return $id;
    }
    
    /**
     * Check if a URL is from Google's image services
     *
     * @param string $url URL to check
     * @return bool      True if URL is from Google
     */
    public static function is_google_url($url) {
        return (
            strpos($url, 'googleusercontent.com') !== false ||
            strpos($url, 'blogspot.com') !== false ||
            strpos($url, 'ggpht.com') !== false ||
            strpos($url, 'blogger.com') !== false ||
            strpos($url, 'bp.blogspot.com') !== false
        );
    }
    
    /**
     * Fix Google image URLs to make them importable
     * 
     * @param string $url Image URL
     * @return string     Fixed URL with proper parameters
     */
    public static function fix_google_image_url($url) {
        if (self::is_google_url($url)) {
            // Remove any size parameters that might restrict image size
            $url = preg_replace('/=s\d+(-c)?/', '', $url);
            $url = preg_replace('/=w\d+(-h\d+)?(-c)?/', '', $url);
            
            // Add size parameter if not present
            if (strpos($url, '?') === false) {
                $url .= '?imgmax=2000';
            } elseif (strpos($url, 'imgmax=') === false) {
                $url .= '&imgmax=2000';
            }
        }
        
        return $url;
    }
    
    /**
     * Get file extension from content type
     *
     * @param string $content_type Content type header
     * @return string              File extension without dot
     */
    public static function get_extension_from_content_type($content_type) {
        $extension = '';
        
        if (strpos($content_type, 'image/jpeg') !== false) {
            $extension = 'jpg';
        } elseif (strpos($content_type, 'image/png') !== false) {
            $extension = 'png';
        } elseif (strpos($content_type, 'image/gif') !== false) {
            $extension = 'gif';
        } elseif (strpos($content_type, 'image/webp') !== false) {
            $extension = 'webp';
        } elseif (strpos($content_type, 'image/') !== false) {
            // Extract extension from content type for other image types
            $extension = str_replace('image/', '', $content_type);
        }
        
        return $extension;
    }
    
    /**
     * Verify and fix the file extension based on actual content
     *
     * @param string $file_path File path
     * @param string $filename  Original filename
     * @return string|WP_Error  Updated file path or error
     */
    public static function verify_and_fix_file_extension($file_path, $filename) {
        if (!file_exists($file_path)) {
            return new WP_Error('file_not_found', __('File not found for extension verification', 'blogger-import-opensource'));
        }
        
        // Check file type
        $file_type = wp_check_filetype($filename, null);
        
        if (empty($file_type['type'])) {
            // Try to determine file type from content
            if (function_exists('exif_imagetype')) {
                $image_type = @exif_imagetype($file_path);
                
                if ($image_type) {
                    $mime = image_type_to_mime_type($image_type);
                    $ext = image_type_to_extension($image_type, false);
                    
                    if (!empty($ext)) {
                        $new_file_path = $file_path . '.' . $ext;
                        rename($file_path, $new_file_path);
                        return $new_file_path;
                    }
                }
            }
            
            // If exif_imagetype is not available or fails, try to examine file content
            $file_header = file_get_contents($file_path, false, null, 0, 12);
            
            if (strpos($file_header, "\xFF\xD8\xFF") === 0) {
                // JPEG signature
                $new_file_path = $file_path . '.jpg';
                rename($file_path, $new_file_path);
                return $new_file_path;
            } elseif (strpos($file_header, "\x89PNG\x0D\x0A\x1A\x0A") === 0) {
                // PNG signature
                $new_file_path = $file_path . '.png';
                rename($file_path, $new_file_path);
                return $new_file_path;
            } elseif (strpos($file_header, "GIF8") === 0) {
                // GIF signature
                $new_file_path = $file_path . '.gif';
                rename($file_path, $new_file_path);
                return $new_file_path;
            } elseif (strpos($file_header, "RIFF") === 0 && strpos($file_header, "WEBP") !== false) {
                // WEBP signature
                $new_file_path = $file_path . '.webp';
                rename($file_path, $new_file_path);
                return $new_file_path;
            }
            
            // Default to JPG if we can't determine the type
            $new_file_path = $file_path . '.jpg';
            rename($file_path, $new_file_path);
            return $new_file_path;
        }
        
        return $file_path;
    }
}

/**
 * Import a media file
 *
 * @param string $url     URL of the media
 * @param int    $post_id Post ID
 * @return int|WP_Error   Attachment ID or error
 */
function bio_import_media($url, $post_id = 0) {
    return BIO_Media_Handler::import_media($url, $post_id);
}

/**
 * Import media files for a post
 *
 * @param int   $post_id        Post ID
 * @param array $urls           Media URLs
 * @param bool  $update_content Whether to update post content
 * @return array                Results
 */
function bio_import_post_media($post_id, $urls = array(), $update_content = true) {
    return BIO_Media_Handler::import_post_media($post_id, $urls, $update_content);
}

/**
 * Import all media files
 *
 * @param array $post_media_map Post ID => URLs mapping
 * @return array                Results
 */
function bio_import_all_media($post_media_map) {
    return BIO_Media_Handler::import_all_media($post_media_map);
}

// Register action to process media after post import
add_action('bio_post_has_media', 'bio_handle_post_media', 10, 2);

/**
 * Handle media for a post
 *
 * @param int   $post_id    Post ID
 * @param array $media_urls Media URLs
 * @return void
 */
function bio_handle_post_media($post_id, $media_urls) {
    if (!empty($media_urls)) {
        bio_import_post_media($post_id, $media_urls, true);
    }
}

// Add support for additional mime types
add_filter('upload_mimes', 'bio_add_mime_types');

/**
 * Add support for additional mime types
 *
 * @param array $mimes Mime types
 * @return array       Updated mime types
 */
function bio_add_mime_types($mimes) {
    $mimes['webp'] = 'image/webp';
    return $mimes;
}