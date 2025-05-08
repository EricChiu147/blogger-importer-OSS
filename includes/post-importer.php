<?php
/**
 * Post Importer for Blogger Import Open Source
 *
 * This file handles importing Blogger posts and pages into WordPress.
 *
 * @package Blogger_Import_OpenSource
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Class responsible for importing posts and pages
 */
class BIO_Post_Importer {
    /**
     * Import a post or page
     *
     * @param array $post_data Blogger post data
     * @return int|WP_Error    WordPress post ID or WP_Error on failure
     */
    public static function import_post($post_data) {
        // Default post type based on entry type
        $post_type = ($post_data['type'] === 'page') ? 'page' : 'post';
        
        // Check if post already exists
        $existing_post_id = BIO_DB_Handler::get_wp_post_id_from_blogger_id($post_data['id']);
        if ($existing_post_id) {
            return $existing_post_id; // Post already imported
        }
        
        // Convert content to blocks
        $blocks_content = bio_convert_to_blocks($post_data['content']);
        
        // Format dates
        $post_date = bio_format_date($post_data['published']);
        $post_modified = bio_format_date($post_data['updated']);
        
        // Generate unique slug
        $slug = bio_generate_unique_slug($post_data['title'], $post_type);
        
        // Create post array
        $wp_post = array(
            'post_title'    => $post_data['title'],
            'post_content'  => $blocks_content,
            'post_status'   => $post_data['status'],
            'post_author'   => get_current_user_id(), // Default to current user
            'post_type'     => $post_type,
            'post_name'     => $slug,
            'post_date'     => $post_date,
            'post_date_gmt' => get_gmt_from_date($post_date),
            'post_modified' => $post_modified,
            'post_modified_gmt' => get_gmt_from_date($post_modified),
            'comment_status' => 'open',
            'ping_status'   => 'open',
        );
        
        // Allow filtering of post data
        $wp_post = apply_filters('bio_pre_insert_post', $wp_post, $post_data);
        
        // Insert the post with error handling and retry
        $operation_id = 'insert_post_' . md5($post_data['id']);
        $post_id = BIO_Error_Handler::execute_with_retry(
            'wp_insert_post',
            array($wp_post, true), // true to return WP_Error on failure
            $operation_id,
            'Inserting post: ' . $post_data['title']
        );
        
        if (is_wp_error($post_id)) {
            BIO_Error_Handler::log_error(
                sprintf('Failed to insert post "%s": %s', $post_data['title'], $post_id->get_error_message()),
                'post_import'
            );
            return $post_id;
        }
        
        // Store mapping data
        BIO_DB_Handler::store_post_mapping($post_data['id'], $post_id, $post_data['permalink']);
        
        // Add tags/categories
        if (!empty($post_data['tags'])) {
            BIO_Tag_Handler::import_tags($post_id, $post_data['tags']);
        }
        
        // Process media in the content
        if (!empty($post_data['media_urls'])) {
            // This will be handled by Media Handler after implementation
            do_action('bio_post_has_media', $post_id, $post_data['media_urls']);
        }
        
        do_action('bio_after_import_post', $post_id, $post_data);
        
        return $post_id;
    }
    
    /**
     * Import multiple posts
     *
     * @param array $posts     Array of post data
     * @param bool  $show_progress Whether to update progress (default: true)
     * @return array           Results with success and failure counts
     */
    public static function import_posts($posts, $show_progress = true) {
        $results = array(
            'total' => count($posts),
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
            'post_ids' => array()
        );
        
        $total_posts = count($posts);
        $current = 0;
        
        foreach ($posts as $post_data) {
            $current++;
            
            // Update progress
            if ($show_progress) {
                $progress = array(
                    'step' => 'import_posts',
                    'current' => $current,
                    'total' => $total_posts,
                    'percentage' => ($total_posts > 0) ? round(($current / $total_posts) * 100) : 0,
                    'message' => sprintf(__('Importing post %d of %d: %s', 'blogger-import-opensource'), 
                                       $current, $total_posts, $post_data['title'])
                );
                BIO_DB_Handler::update_import_progress($progress);
            }
            
            // Check if post already exists
            $existing_post_id = BIO_DB_Handler::get_wp_post_id_from_blogger_id($post_data['id']);
            if ($existing_post_id) {
                $results['skipped']++;
                $results['post_ids'][] = $existing_post_id;
                continue;
            }
            
            // Import the post
            $post_id = self::import_post($post_data);
            
            if (is_wp_error($post_id)) {
                $results['failed']++;
            } else {
                $results['success']++;
                $results['post_ids'][] = $post_id;
            }
            
            // Give the server a small break
            usleep(50000); // 0.05 seconds
        }
        
        return $results;
    }
    
    /**
     * Find a WordPress author ID based on Blogger author info
     *
     * @param array $author_data Blogger author data
     * @return int               WordPress author ID
     */
    public static function find_author_id($author_data) {
        // Try to find by email
        if (!empty($author_data['email'])) {
            $user = get_user_by('email', $author_data['email']);
            if ($user) {
                return $user->ID;
            }
        }
        
        // Try to find by login (name)
        if (!empty($author_data['name'])) {
            $user = get_user_by('login', $author_data['name']);
            if ($user) {
                return $user->ID;
            }
        }
        
        // Return default admin user
        $admins = get_users(array('role' => 'administrator', 'number' => 1));
        if (!empty($admins)) {
            return $admins[0]->ID;
        }
        
        // Fallback to current user
        return get_current_user_id();
    }
}

/**
 * Import a post or page
 *
 * @param array $post_data Post data
 * @return int|WP_Error    Post ID or WP_Error
 */
function bio_import_post($post_data) {
    return BIO_Post_Importer::import_post($post_data);
}

/**
 * Import multiple posts
 *
 * @param array $posts Array of post data
 * @param bool  $show_progress Whether to update progress
 * @return array       Results
 */
function bio_import_posts($posts, $show_progress = true) {
    return BIO_Post_Importer::import_posts($posts, $show_progress);
}

/**
 * Get WordPress user ID from Blogger author
 *
 * @param string $blogger_author Blogger author name or email
 * @return int|false WordPress user ID or false if not found
 */
function bio_get_author_id($blogger_author) {
    // First try to find by email
    $user = get_user_by('email', $blogger_author);
    
    if ($user) {
        return $user->ID;
    }
    
    // Then try by login/username
    $user = get_user_by('login', $blogger_author);
    
    if ($user) {
        return $user->ID;
    }
    
    // Then try by display name
    $users = get_users(array(
        'meta_key' => 'display_name',
        'meta_value' => $blogger_author
    ));
    
    if (!empty($users) && isset($users[0])) {
        return $users[0]->ID;
    }
    
    return false;
}