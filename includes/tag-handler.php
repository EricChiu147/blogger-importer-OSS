<?php
/**
 * Tag Handler for Blogger Import Open Source
 *
 * This file handles importing Blogger labels as WordPress tags/categories.
 *
 * @package Blogger_Import_OpenSource
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Class responsible for handling tags and categories
 */
class BIO_Tag_Handler {
    /**
     * Import tags/labels for a post
     *
     * @param int   $post_id WordPress post ID
     * @param array $tags    Array of tags
     * @return array         Results with counts
     */
    public static function import_tags($post_id, $tags) {
        if (empty($tags) || empty($post_id)) {
            return array(
                'added' => 0,
                'failed' => 0
            );
        }
        
        $results = array(
            'added' => 0,
            'failed' => 0
        );
        
        // Filter tags before processing
        $tags = apply_filters('bio_pre_import_tags', $tags, $post_id);
        
        // Set tags for the post
        $term_ids = array();
        
        foreach ($tags as $tag_name) {
            // Sanitize tag name
            $tag_name = trim($tag_name);
            
            // Fix encoding for the tag name
            if (function_exists('bio_fix_encoding')) {
                $tag_name = bio_fix_encoding($tag_name);
            }
            
            // Skip empty tags
            if (empty($tag_name)) {
                continue;
            }
            
            // Check if tag exists or create it
            $tag = get_term_by('name', $tag_name, 'post_tag');
            
            if (!$tag) {
                $tag_data = wp_insert_term($tag_name, 'post_tag');
                
                if (is_wp_error($tag_data)) {
                    BIO_Error_Handler::log_error(
                        sprintf('Failed to create tag "%s": %s', $tag_name, $tag_data->get_error_message()),
                        'tag_import'
                    );
                    $results['failed']++;
                    continue;
                }
                
                $term_ids[] = $tag_data['term_id'];
                $results['added']++;
            } else {
                $term_ids[] = $tag->term_id;
                $results['added']++;
            }
        }
        
        // Set tags for the post
        if (!empty($term_ids)) {
            $set_tags = wp_set_post_tags($post_id, $term_ids, true);
            
            if (is_wp_error($set_tags)) {
                BIO_Error_Handler::log_error(
                    sprintf('Failed to set tags for post %d: %s', $post_id, $set_tags->get_error_message()),
                    'tag_import'
                );
            }
        }
        
        do_action('bio_after_import_tags', $post_id, $tags, $results);
        
        return $results;
    }
    
    /**
     * Create a category from a tag if it matches certain patterns
     *
     * @param string $tag_name Tag name
     * @return int|WP_Error    Term ID or WP_Error
     */
    public static function maybe_create_category($tag_name) {
        // Check if this tag should be a category based on configuration
        $create_category = apply_filters('bio_tag_should_be_category', false, $tag_name);
        
        if (!$create_category) {
            return false;
        }
        
        // Check if category exists
        $category = get_term_by('name', $tag_name, 'category');
        
        if ($category) {
            return $category->term_id;
        }
        
        // Create category
        $category_data = wp_insert_term($tag_name, 'category');
        
        if (is_wp_error($category_data)) {
            BIO_Error_Handler::log_error(
                sprintf('Failed to create category "%s": %s', $tag_name, $category_data->get_error_message()),
                'category_import'
            );
            return $category_data;
        }
        
        return $category_data['term_id'];
    }
    
    /**
     * Check if a tag should be imported
     *
     * @param string $tag_name Tag name
     * @return bool            Whether to import
     */
    public static function should_import_tag($tag_name) {
        // Skip empty tags
        if (empty(trim($tag_name))) {
            return false;
        }
        
        // Allow filtering
        return apply_filters('bio_should_import_tag', true, $tag_name);
    }
}

/**
 * Import tags for a post
 *
 * @param int   $post_id Post ID
 * @param array $tags    Tags
 * @return array         Results
 */
function bio_import_tags($post_id, $tags) {
    return BIO_Tag_Handler::import_tags($post_id, $tags);
}