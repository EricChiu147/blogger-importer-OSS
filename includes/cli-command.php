<?php
/**
 * WP-CLI Command for Blogger Import Open Source
 *
 * This file registers a WP-CLI command for importing Blogger content.
 *
 * @package Blogger_Import_OpenSource
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Only load if WP-CLI is available
if (defined('WP_CLI') && WP_CLI) {

    /**
     * Import content from Blogger XML export files.
     */
    class BIO_CLI_Command {
        /**
         * Import content from a Blogger XML export file.
         *
         * ## OPTIONS
         *
         * <file>
         * : Path to the Blogger XML export file.
         *
         * [--skip-media]
         * : Skip importing media files.
         *
         * [--map-file=<file>]
         * : Path to output mapping file (CSV format).
         *
         * [--author=<id>]
         * : User ID to use as the author of imported content.
         *
         * ## EXAMPLES
         *
         *     wp blogger-import import /path/to/blogger-export.xml
         *     wp blogger-import import /path/to/blogger-export.xml --skip-media
         *     wp blogger-import import /path/to/blogger-export.xml --map-file=/path/to/mapping.csv
         *
         * @param array $args       Command arguments
         * @param array $assoc_args Command associative arguments
         * @return void
         */
        public function import($args, $assoc_args) {
            // Check if file exists
            if (!isset($args[0])) {
                WP_CLI::error('XML file is required.');
                return;
            }
            
            $file_path = $args[0];
            
            if (!file_exists($file_path)) {
                WP_CLI::error('File not found: ' . $file_path);
                return;
            }
            
            // Parse options
            $skip_media = isset($assoc_args['skip-media']);
            $map_file = isset($assoc_args['map-file']) ? $assoc_args['map-file'] : '';
            $author_id = isset($assoc_args['author']) ? (int) $assoc_args['author'] : get_current_user_id();
            
            // Check author exists
            $author = get_user_by('id', $author_id);
            if (!$author) {
                WP_CLI::error('Author not found. User ID: ' . $author_id);
                return;
            }
            
            WP_CLI::log('Starting Blogger import...');
            WP_CLI::log('Parsing XML file: ' . $file_path);
            
            // Parse XML file
            $parse_result = bio_parse_blogger_xml($file_path);
            
            if (is_wp_error($parse_result)) {
                WP_CLI::error('Failed to parse XML: ' . $parse_result->get_error_message());
                return;
            }
            
            $data = $parse_result['data'];
            $stats = $parse_result['stats'];
            
            WP_CLI::log(sprintf(
                'Found %d posts, %d pages, %d comments, and %d tags.',
                $stats['posts'],
                $stats['pages'],
                $stats['comments'],
                $stats['tags']
            ));
            
            // Import posts
            WP_CLI::log('Importing posts...');
            
            $progress = \WP_CLI\Utils\make_progress_bar('Importing posts', count($data['posts']));
            
            $post_results = array(
                'success' => 0,
                'failed' => 0,
                'skipped' => 0,
                'post_ids' => array()
            );
            
            foreach ($data['posts'] as $post) {
                // Override author
                $post['author_override'] = $author_id;
                
                // Check if post already exists
                $existing_post_id = BIO_DB_Handler::get_wp_post_id_from_blogger_id($post['id']);
                
                if ($existing_post_id) {
                    $post_results['skipped']++;
                    $post_results['post_ids'][] = $existing_post_id;
                } else {
                    // Import post
                    $post_id = bio_import_post($post);
                    
                    if (is_wp_error($post_id)) {
                        WP_CLI::warning('Failed to import post: ' . $post['title'] . ' - ' . $post_id->get_error_message());
                        $post_results['failed']++;
                    } else {
                        $post_results['success']++;
                        $post_results['post_ids'][] = $post_id;
                    }
                }
                
                $progress->tick();
            }
            
            $progress->finish();
            
            // Import pages
            WP_CLI::log('Importing pages...');
            
            $progress = \WP_CLI\Utils\make_progress_bar('Importing pages', count($data['pages']));
            
            $page_results = array(
                'success' => 0,
                'failed' => 0,
                'skipped' => 0,
                'post_ids' => array()
            );
            
            foreach ($data['pages'] as $page) {
                // Override author
                $page['author_override'] = $author_id;
                
                // Check if page already exists
                $existing_page_id = BIO_DB_Handler::get_wp_post_id_from_blogger_id($page['id']);
                
                if ($existing_page_id) {
                    $page_results['skipped']++;
                    $page_results['post_ids'][] = $existing_page_id;
                } else {
                    // Import page
                    $page_id = bio_import_post($page);
                    
                    if (is_wp_error($page_id)) {
                        WP_CLI::warning('Failed to import page: ' . $page['title'] . ' - ' . $page_id->get_error_message());
                        $page_results['failed']++;
                    } else {
                        $page_results['success']++;
                        $page_results['post_ids'][] = $page_id;
                    }
                }
                
                $progress->tick();
            }
            
            $progress->finish();
            
            // Import comments
            WP_CLI::log('Importing comments...');
            
            // Build post mapping
            $post_mapping = array();
            
            foreach ($post_results['post_ids'] as $post_id) {
                $blogger_id = get_post_meta($post_id, '_bio_blogger_id', true);
                if (!empty($blogger_id)) {
                    $post_mapping[$blogger_id] = $post_id;
                }
            }
            
            foreach ($page_results['post_ids'] as $page_id) {
                $blogger_id = get_post_meta($page_id, '_bio_blogger_id', true);
                if (!empty($blogger_id)) {
                    $post_mapping[$blogger_id] = $page_id;
                }
            }
            
            $progress = \WP_CLI\Utils\make_progress_bar('Importing comments', count($data['comments']));
            
            $comment_results = bio_import_all_comments($data['comments'], $post_mapping, false);
            
            foreach ($data['comments'] as $comment) {
                $progress->tick();
            }
            
            $progress->finish();
            
            // Import media
            if (!$skip_media) {
                WP_CLI::log('Importing media...');
                
                // Collect media URLs from posts and pages
                $post_media_map = array();
                
                foreach (array_merge($data['posts'], $data['pages']) as $content_item) {
                    if (!empty($content_item['media_urls'])) {
                        // Find WordPress post ID
                        $wp_post_id = BIO_DB_Handler::get_wp_post_id_from_blogger_id($content_item['id']);
                        
                        if ($wp_post_id) {
                            $post_media_map[$wp_post_id] = $content_item['media_urls'];
                        }
                    }
                }
                
                $media_count = 0;
                foreach ($post_media_map as $urls) {
                    $media_count += count($urls);
                }
                
                $progress = \WP_CLI\Utils\make_progress_bar('Importing media', $media_count);
                
                $media_results = array(
                    'total' => 0,
                    'success' => 0,
                    'failed' => 0,
                    'skipped' => 0
                );
                
                foreach ($post_media_map as $post_id => $urls) {
                    foreach ($urls as $url) {
                        // Check if already imported
                        $existing_id = BIO_Media_Handler::get_attachment_id_by_url($url);
                        
                        if ($existing_id) {
                            $media_results['skipped']++;
                        } else {
                            // Import the media
                            $attachment_id = bio_import_media($url, $post_id);
                            
                            if (is_wp_error($attachment_id)) {
                                WP_CLI::warning('Failed to import media: ' . $url . ' - ' . $attachment_id->get_error_message());
                                $media_results['failed']++;
                            } else {
                                $media_results['success']++;
                            }
                        }
                        
                        $media_results['total']++;
                        $progress->tick();
                    }
                }
                
                $progress->finish();
                
                // Update post content with new media URLs
                WP_CLI::log('Updating content with new media URLs...');
                
                foreach ($post_media_map as $post_id => $urls) {
                    $post = get_post($post_id);
                    
                    if ($post) {
                        $content = $post->post_content;
                        $content_updated = false;
                        
                        foreach ($urls as $old_url) {
                            $attachment_id = BIO_Media_Handler::get_attachment_id_by_url($old_url);
                            
                            if ($attachment_id) {
                                $new_url = wp_get_attachment_url($attachment_id);
                                
                                if ($new_url && $new_url !== $old_url) {
                                    $content = str_replace($old_url, $new_url, $content);
                                    $content_updated = true;
                                }
                            }
                        }
                        
                        if ($content_updated) {
                            wp_update_post(array(
                                'ID' => $post_id,
                                'post_content' => $content
                            ));
                        }
                    }
                }
            }
            
            // Generate mapping file if requested
            if (!empty($map_file)) {
                WP_CLI::log('Generating mapping file: ' . $map_file);
                
                $mapping_data = bio_generate_mapping_data();
                
                // Determine format based on file extension
                $extension = pathinfo($map_file, PATHINFO_EXTENSION);
                $mapping_content = '';
                
                switch (strtolower($extension)) {
                    case 'json':
                        $mapping_content = bio_export_mapping_as_json();
                        break;
                    case 'php':
                        $mapping_content = bio_export_mapping_as_php();
                        break;
                    case 'htaccess':
                        $mapping_content = bio_export_mapping_as_htaccess();
                        break;
                    case 'conf':
                        $mapping_content = bio_export_mapping_as_nginx();
                        break;
                    case 'csv':
                    default:
                        $mapping_content = bio_export_mapping_as_csv();
                        break;
                }
                
                // Write to file
                if (file_put_contents($map_file, $mapping_content)) {
                    WP_CLI::success('Mapping file created: ' . $map_file);
                } else {
                    WP_CLI::error('Failed to create mapping file: ' . $map_file);
                }
            }
            
            // Display summary
            WP_CLI::success('Import completed!');
            WP_CLI::log(sprintf(
                'Imported: %d posts, %d pages, %d comments, %d media files.',
                $post_results['success'],
                $page_results['success'],
                $comment_results['success'],
                isset($media_results['success']) ? $media_results['success'] : 0
            ));
            
            if ($post_results['skipped'] > 0 || $page_results['skipped'] > 0) {
                WP_CLI::log(sprintf(
                    'Skipped: %d posts, %d pages (already imported).',
                    $post_results['skipped'],
                    $page_results['skipped']
                ));
            }
            
            if ($post_results['failed'] > 0 || $page_results['failed'] > 0 || 
                $comment_results['failed'] > 0 || (isset($media_results['failed']) && $media_results['failed'] > 0)) {
                WP_CLI::warning(sprintf(
                    'Failed: %d posts, %d pages, %d comments, %d media files.',
                    $post_results['failed'],
                    $page_results['failed'],
                    $comment_results['failed'],
                    isset($media_results['failed']) ? $media_results['failed'] : 0
                ));
            }
        }
        
        /**
         * Export URL mapping
         *
         * ## OPTIONS
         *
         * <file>
         * : Path to the output file.
         *
         * [--format=<format>]
         * : Format of the mapping file. Options: csv, json, php, htaccess, nginx
         * ---
         * default: csv
         * options:
         *   - csv
         *   - json
         *   - php
         *   - htaccess
         *   - nginx
         * ---
         *
         * ## EXAMPLES
         *
         *     wp blogger-import export-mapping /path/to/mapping.csv
         *     wp blogger-import export-mapping /path/to/mapping.json --format=json
         *     wp blogger-import export-mapping /path/to/redirects.php --format=php
         *
         * @param array $args       Command arguments
         * @param array $assoc_args Command associative arguments
         * @return void
         */
        public function export_mapping($args, $assoc_args) {
            // Check if file path is provided
            if (!isset($args[0])) {
                WP_CLI::error('Output file path is required.');
                return;
            }
            
            $file_path = $args[0];
            
            // Check if directory exists
            $dir = dirname($file_path);
            if (!is_dir($dir)) {
                WP_CLI::error('Directory does not exist: ' . $dir);
                return;
            }
            
            // Check if directory is writable
            if (!is_writable($dir)) {
                WP_CLI::error('Directory is not writable: ' . $dir);
                return;
            }
            
            // Get format
            $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'csv';
            
            // Generate mapping data
            WP_CLI::log('Generating mapping data...');
            $mapping_data = bio_generate_mapping_data();
            
            if (empty($mapping_data)) {
                WP_CLI::error('No mapping data found. Have you imported any content?');
                return;
            }
            
            WP_CLI::log('Found ' . count($mapping_data) . ' items to map.');
            
            // Generate mapping content based on format
            $mapping_content = '';
            
            switch ($format) {
                case 'json':
                    $mapping_content = bio_export_mapping_as_json();
                    break;
                    
                case 'php':
                    $mapping_content = bio_export_mapping_as_php();
                    break;
                    
                case 'htaccess':
                    $mapping_content = bio_export_mapping_as_htaccess();
                    break;
                    
                case 'nginx':
                    $mapping_content = bio_export_mapping_as_nginx();
                    break;
                    
                case 'csv':
                default:
                    $mapping_content = bio_export_mapping_as_csv();
                    break;
            }
            
            // Write to file
            if (file_put_contents($file_path, $mapping_content)) {
                WP_CLI::success('Mapping file created: ' . $file_path);
            } else {
                WP_CLI::error('Failed to create mapping file: ' . $file_path);
            }
        }
        
        /**
         * Delete all imported content
         *
         * ## OPTIONS
         *
         * [--include=<types>]
         * : Types of content to delete. Options: posts, pages, comments, media
         * ---
         * default: posts,pages,comments,media
         * ---
         *
         * [--yes]
         * : Skip confirmation prompt
         *
         * ## EXAMPLES
         *
         *     wp blogger-import cleanup --yes
         *     wp blogger-import cleanup --include=posts,pages
         *
         * @param array $args       Command arguments
         * @param array $assoc_args Command associative arguments
         * @return void
         */
        public function cleanup($args, $assoc_args) {
            $include = isset($assoc_args['include']) ? $assoc_args['include'] : 'posts,pages,comments,media';
            $include_types = explode(',', $include);
            
            // Confirm deletion
            if (!isset($assoc_args['yes'])) {
                WP_CLI::confirm('Are you sure you want to delete all imported content? This action cannot be undone.');
            }
            
            // Get all post mappings
            $mappings = bio_generate_mapping_data();
            
            $deleted_posts = 0;
            $deleted_pages = 0;
            $deleted_comments = 0;
            $deleted_media = 0;
            
            // Delete posts and pages
            if (in_array('posts', $include_types) || in_array('pages', $include_types)) {
                foreach ($mappings as $mapping) {
                    $post_type = $mapping['post_type'];
                    $post_id = $mapping['wp_id'];
                    
                    if ($post_type == 'post' && in_array('posts', $include_types)) {
                        if (wp_delete_post($post_id, true)) {
                            $deleted_posts++;
                        }
                    } elseif ($post_type == 'page' && in_array('pages', $include_types)) {
                        if (wp_delete_post($post_id, true)) {
                            $deleted_pages++;
                        }
                    }
                }
            }
            
            // Delete comments
            if (in_array('comments', $include_types)) {
                global $wpdb;
                $comment_ids = $wpdb->get_col(
                    "SELECT comment_id FROM {$wpdb->commentmeta} WHERE meta_key = '_bio_blogger_comment_id'"
                );
                
                foreach ($comment_ids as $comment_id) {
                    if (wp_delete_comment($comment_id, true)) {
                        $deleted_comments++;
                    }
                }
            }
            
            // Delete media
            if (in_array('media', $include_types)) {
                global $wpdb;
                $media_ids = $wpdb->get_col(
                    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_bio_original_url'"
                );
                
                foreach ($media_ids as $media_id) {
                    if (wp_delete_attachment($media_id, true)) {
                        $deleted_media++;
                    }
                }
            }
            
            // Delete mappings
            delete_option('bio_post_mappings');
            delete_option('bio_comment_mappings');
            delete_option('bio_import_stats');
            
            // Display summary
            WP_CLI::success('Cleanup completed!');
            WP_CLI::log(sprintf(
                'Deleted: %d posts, %d pages, %d comments, %d media files.',
                $deleted_posts,
                $deleted_pages,
                $deleted_comments,
                $deleted_media
            ));
        }
    }
    
    // Register the command
    WP_CLI::add_command('blogger-import', 'BIO_CLI_Command');
}