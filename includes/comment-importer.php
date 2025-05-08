<?php
/**
 * Comment Importer for Blogger Import Open Source
 *
 * This file handles importing Blogger comments into WordPress.
 *
 * @package Blogger_Import_OpenSource
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Class responsible for importing comments
 */
class BIO_Comment_Importer {
    /**
     * Import a comment
     *
     * @param array $comment_data Blogger comment data
     * @param int   $wp_post_id   WordPress post ID
     * @return int|WP_Error       Comment ID or WP_Error
     */
    public static function import_comment($comment_data, $wp_post_id) {
        // Check if comment already exists
        $existing_comment_id = BIO_DB_Handler::get_wp_comment_id_from_blogger_id($comment_data['id']);
        if ($existing_comment_id) {
            return $existing_comment_id; // Already imported
        }
        
        // Fix encoding for comment content and author name
        if (isset($comment_data['content'])) {
            $comment_data['content'] = self::fix_encoding($comment_data['content']);
        }
        
        if (isset($comment_data['author']['name'])) {
            $comment_data['author']['name'] = self::fix_encoding($comment_data['author']['name']);
        }
        
        // Format dates
        $comment_date = bio_format_date($comment_data['published']);
        
        // Create comment data array
        $wp_comment = array(
            'comment_post_ID' => $wp_post_id,
            'comment_author' => $comment_data['author']['name'],
            'comment_author_email' => $comment_data['author']['email'],
            'comment_author_url' => $comment_data['author']['url'],
            'comment_content' => $comment_data['content'],
            'comment_date' => $comment_date,
            'comment_date_gmt' => get_gmt_from_date($comment_date),
            'comment_approved' => 1, // Approved
            'comment_parent' => 0, // Will be updated later if needed
            'comment_type' => '',
        );
        
        // Allow filtering
        $wp_comment = apply_filters('bio_pre_insert_comment', $wp_comment, $comment_data);
        
        // Insert comment with retry
        $operation_id = 'insert_comment_' . md5($comment_data['id']);
        $comment_id = BIO_Error_Handler::execute_with_retry(
            'wp_insert_comment',
            array($wp_comment),
            $operation_id,
            'Inserting comment by: ' . $comment_data['author']['name']
        );
        
        if (!$comment_id || is_wp_error($comment_id)) {
            BIO_Error_Handler::log_error(
                sprintf('Failed to insert comment by "%s": %s', 
                    $comment_data['author']['name'], 
                    is_wp_error($comment_id) ? $comment_id->get_error_message() : 'Unknown error'
                ),
                'comment_import'
            );
            return is_wp_error($comment_id) ? $comment_id : new WP_Error('comment_insert_failed', 'Failed to insert comment');
        }
        
        // Store mapping
        BIO_DB_Handler::store_comment_mapping($comment_data['id'], $comment_id);
        
        do_action('bio_after_import_comment', $comment_id, $comment_data);
        
        return $comment_id;
    }
    
    /**
     * Import multiple comments for a post
     *
     * @param array $comments   Array of comment data
     * @param int   $wp_post_id WordPress post ID
     * @param bool  $show_progress Whether to update progress
     * @return array            Results
     */
    public static function import_comments($comments, $wp_post_id, $show_progress = true) {
        $results = array(
            'total' => count($comments),
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
        );
        
        $total_comments = count($comments);
        $current = 0;
        
        // First pass: import all comments
        $comment_id_map = array(); // Blogger comment ID => WP comment ID
        
        foreach ($comments as $comment_data) {
            $current++;
            
            // Update progress
            if ($show_progress) {
                // Use fixed encoding for display in progress
                $author_name = isset($comment_data['author']['name']) ? 
                    self::fix_encoding($comment_data['author']['name']) : 
                    __('Unknown Author', 'blogger-import-opensource');
                
                $progress = array(
                    'step' => 'import_comments',
                    'current' => $current,
                    'total' => $total_comments,
                    'percentage' => ($total_comments > 0) ? round(($current / $total_comments) * 100) : 0,
                    'message' => sprintf(__('Importing comment %d of %d by %s', 'blogger-import-opensource'), 
                                       $current, $total_comments, $author_name)
                );
                BIO_DB_Handler::update_import_progress($progress);
            }
            
            // Check if already imported
            $existing_comment_id = BIO_DB_Handler::get_wp_comment_id_from_blogger_id($comment_data['id']);
            if ($existing_comment_id) {
                $results['skipped']++;
                $comment_id_map[$comment_data['id']] = $existing_comment_id;
                continue;
            }
            
            // Import the comment
            $comment_id = self::import_comment($comment_data, $wp_post_id);
            
            if (is_wp_error($comment_id)) {
                $results['failed']++;
            } else {
                $results['success']++;
                $comment_id_map[$comment_data['id']] = $comment_id;
            }
            
            // Give the server a small break
            usleep(20000); // 0.02 seconds
        }
        
        // Second pass: update parent relationships
        $current = 0;
        foreach ($comments as $comment_data) {
            $current++;
            
            // Skip if comment wasn't imported
            if (!isset($comment_id_map[$comment_data['id']])) {
                continue;
            }
            
            $wp_comment_id = $comment_id_map[$comment_data['id']];
            
            // Update progress
            if ($show_progress) {
                $progress = array(
                    'step' => 'update_comment_hierarchy',
                    'current' => $current,
                    'total' => $total_comments,
                    'percentage' => ($total_comments > 0) ? round(($current / $total_comments) * 100) : 0,
                    'message' => sprintf(__('Updating comment relationships %d of %d', 'blogger-import-opensource'), 
                                       $current, $total_comments)
                );
                BIO_DB_Handler::update_import_progress($progress);
            }
            
            // Skip if no parent
            if (empty($comment_data['parent_id']) || $comment_data['parent_id'] === 0) {
                continue;
            }
            
            // Check if parent was imported
            if (isset($comment_id_map[$comment_data['parent_id']])) {
                $parent_id = $comment_id_map[$comment_data['parent_id']];
                
                // Update the comment's parent
                wp_update_comment(array(
                    'comment_ID' => $wp_comment_id,
                    'comment_parent' => $parent_id
                ));
            }
        }
        
        return $results;
    }
    
    /**
     * Import all comments for all posts
     *
     * @param array $comments    All comments from Blogger
     * @param array $post_mapping Blogger post ID to WP post ID mapping
     * @param bool  $show_progress Whether to update progress
     * @return array             Results
     */
    public static function import_all_comments($comments, $post_mapping, $show_progress = true) {
        $results = array(
            'total' => count($comments),
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
        );
        
        // Group comments by post ID
        $comments_by_post = array();
        
        foreach ($comments as $comment) {
            $blogger_post_id = $comment['post_id'];
            
            if (!isset($comments_by_post[$blogger_post_id])) {
                $comments_by_post[$blogger_post_id] = array();
            }
            
            $comments_by_post[$blogger_post_id][] = $comment;
        }
        
        // Import comments for each post
        $current_post = 0;
        $total_posts = count($comments_by_post);
        
        foreach ($comments_by_post as $blogger_post_id => $post_comments) {
            $current_post++;
            
            // Find WordPress post ID
            $wp_post_id = isset($post_mapping[$blogger_post_id]) ? $post_mapping[$blogger_post_id] : 
                          BIO_DB_Handler::get_wp_post_id_from_blogger_id($blogger_post_id);
            
            if (!$wp_post_id) {
                // Skip comments if post not found
                $results['failed'] += count($post_comments);
                BIO_Error_Handler::log_error(
                    sprintf('Skipping %d comments for post ID %s - post not imported', 
                            count($post_comments), $blogger_post_id),
                    'comment_import'
                );
                continue;
            }
            
            // Update progress
            if ($show_progress) {
                $progress = array(
                    'step' => 'import_comments_by_post',
                    'current' => $current_post,
                    'total' => $total_posts,
                    'percentage' => ($total_posts > 0) ? round(($current_post / $total_posts) * 100) : 0,
                    'message' => sprintf(__('Importing comments for post %d of %d', 'blogger-import-opensource'), 
                                       $current_post, $total_posts)
                );
                BIO_DB_Handler::update_import_progress($progress);
            }
            
            // Import comments for this post
            $post_results = self::import_comments($post_comments, $wp_post_id, false);
            
            // Add results to overall results
            $results['success'] += $post_results['success'];
            $results['failed'] += $post_results['failed'];
            $results['skipped'] += $post_results['skipped'];
        }
        
        return $results;
    }
    
    /**
     * Fix encoding issues in text, especially for Chinese characters
     *
     * @param string $text Text that may have encoding issues
     * @return string      Properly encoded text
     */
    public static function fix_encoding($text) {
        // Use centralized utility function if available
        if (function_exists('bio_fix_encoding')) {
            return bio_fix_encoding($text);
        }
        
        // Fallback if utility function isn't available
        // This is a simplified fallback implementation
        
        // Fix Unicode escape sequences like u5408u7968 (Chinese characters)
        if (preg_match('/u[0-9a-fA-F]{4}/', $text)) {
            $text = preg_replace_callback('/u([0-9a-fA-F]{4})/', function($matches) {
                return json_decode('"\u' . $matches[1] . '"') ?: $matches[0];
            }, $text);
        }
        
        // Ensure proper UTF-8 encoding
        if (function_exists('mb_check_encoding') && !mb_check_encoding($text, 'UTF-8')) {
            if (function_exists('mb_convert_encoding')) {
                $text = mb_convert_encoding($text, 'UTF-8', 'auto');
            }
        }
        
        return $text;
    }
}

/**
 * Import a comment
 *
 * @param array $comment_data Comment data
 * @param int   $wp_post_id   Post ID
 * @return int|WP_Error       Comment ID or error
 */
function bio_import_comment($comment_data, $wp_post_id) {
    return BIO_Comment_Importer::import_comment($comment_data, $wp_post_id);
}

/**
 * Import comments for a post
 *
 * @param array $comments   Comments
 * @param int   $wp_post_id Post ID
 * @param bool  $show_progress Whether to show progress
 * @return array            Results
 */
function bio_import_comments($comments, $wp_post_id, $show_progress = true) {
    return BIO_Comment_Importer::import_comments($comments, $wp_post_id, $show_progress);
}

/**
 * Import all comments
 *
 * @param array $comments     All comments
 * @param array $post_mapping Post ID mapping
 * @param bool  $show_progress Whether to show progress
 * @return array             Results
 */
function bio_import_all_comments($comments, $post_mapping, $show_progress = true) {
    return BIO_Comment_Importer::import_all_comments($comments, $post_mapping, $show_progress);
}

/**
 * Fix encoding in comment text
 *
 * @param string $text Text to fix encoding issues
 * @return string      Fixed text
 */
function bio_fix_comment_encoding($text) {
    return BIO_Comment_Importer::fix_encoding($text);
}