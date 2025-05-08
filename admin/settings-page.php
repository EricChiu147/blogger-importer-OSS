<?php
/**
 * Admin Settings Page for Blogger Import Open Source
 *
 * This file handles the admin interface for the plugin.
 *
 * @package Blogger_Import_OpenSource
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Class responsible for the admin interface
 */
class BIO_Admin {
    /**
     * Initialize the admin interface
     */
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'register_menu'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
        add_action('admin_post_bio_import', array(__CLASS__, 'handle_import'));
        add_action('admin_post_bio_download_mapping', array(__CLASS__, 'handle_download_mapping'));
        add_action('admin_notices', array(__CLASS__, 'display_notices'));
    }
    
    /**
     * Register admin menu items
     */
    public static function register_menu() {
        // Add to Tools menu
        add_management_page(
            __('Blogger Importer', 'blogger-import-opensource'),
            __('Blogger Importer', 'blogger-import-opensource'),
            'import',
            'blogger-import-os',
            array(__CLASS__, 'render_settings_page')
        );
        
        // Also register with WordPress importer
        if (function_exists('register_importer')) {
            register_importer(
                'blogger-import-os',
                __('Blogger', 'blogger-import-opensource'),
                __('Import posts, pages, comments, and media from a Blogger export file.', 'blogger-import-opensource'),
                array(__CLASS__, 'render_settings_page')
            );
        }
    }
    
    /**
     * Enqueue admin assets
     *
     * @param string $hook Current admin page
     */
    public static function enqueue_assets($hook) {
        if ($hook !== 'tools_page_blogger-import-os' && $hook !== 'admin_page_blogger-import-os') {
            return;
        }
        
        wp_enqueue_style(
            'bio-admin-style', 
            BIO_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            BIO_VERSION
        );
        
        wp_enqueue_script(
            'bio-admin-script',
            BIO_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            BIO_VERSION,
            true
        );
        
        wp_localize_script('bio-admin-script', 'bioAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bio_ajax_nonce'),
            'importRunning' => self::is_import_running() ? 'yes' : 'no',
            'i18n' => array(
                'importing' => __('Importing...', 'blogger-import-opensource'),
                'completed' => __('Import completed!', 'blogger-import-opensource'),
                'failed' => __('Import failed!', 'blogger-import-opensource')
            )
        ));
    }
    
    /**
     * Render the settings page
     */
    public static function render_settings_page() {
        // Check permissions
        if (!current_user_can('import')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'blogger-import-opensource'));
        }
        
        // Check if import is running
        $import_running = self::is_import_running();
        $import_progress = $import_running ? BIO_DB_Handler::get_import_progress() : false;
        
        // Check for import stats
        $import_stats = BIO_DB_Handler::get_import_stats();
        $show_stats = !empty($import_stats);
        
        // Get max upload size
        $max_upload_size = bio_get_max_upload_size();
        $formatted_max_size = bio_format_size($max_upload_size);
        
        // Include the admin template
        include BIO_PLUGIN_DIR . 'admin/templates/settings-page.php';
    }
    
    /**
     * Check if an import is currently running
     *
     * @return bool Import status
     */
    public static function is_import_running() {
        $progress = BIO_DB_Handler::get_import_progress();
        return !empty($progress);
    }
    
    /**
     * Display admin notices
     */
    public static function display_notices() {
        // Display success message if import completed
        if (isset($_GET['bio_import_success'])) {
            $stats = array(
                'posts' => isset($_GET['posts']) ? intval($_GET['posts']) : 0,
                'pages' => isset($_GET['pages']) ? intval($_GET['pages']) : 0,
                'comments' => isset($_GET['comments']) ? intval($_GET['comments']) : 0,
                'media' => isset($_GET['media']) ? intval($_GET['media']) : 0,
            );
            
            $message = sprintf(
                __('Blogger import completed successfully. Imported: %d posts, %d pages, %d comments, and %d media files.', 'blogger-import-opensource'),
                $stats['posts'],
                $stats['pages'],
                $stats['comments'],
                $stats['media']
            );
            
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }
        
        // Display error message if import failed
        if (isset($_GET['bio_import_error'])) {
            $error_message = isset($_GET['message']) ? urldecode($_GET['message']) : __('Unknown error', 'blogger-import-opensource');
            
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($error_message) . '</p></div>';
        }
    }
    
    /**
     * Handle import form submission
     */
    public static function handle_import() {
        // Check permissions
        if (!current_user_can('import')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'blogger-import-opensource'));
        }
        
        // Verify nonce
        if (
            !isset($_POST['bio_import_nonce']) || 
            !wp_verify_nonce($_POST['bio_import_nonce'], 'bio_import')
        ) {
            wp_die(__('Security check failed.', 'blogger-import-opensource'));
        }
        
        // Check if an import is already running
        if (self::is_import_running()) {
            wp_redirect(admin_url('tools.php?page=blogger-import-os&bio_import_error=1&message=' . urlencode(__('An import is already in progress.', 'blogger-import-opensource'))));
            exit;
        }
        
        // Check if file was uploaded
        if (!isset($_FILES['blogger_xml']) || empty($_FILES['blogger_xml']['tmp_name'])) {
            wp_redirect(admin_url('tools.php?page=blogger-import-os&bio_import_error=1&message=' . urlencode(__('No file was uploaded.', 'blogger-import-opensource'))));
            exit;
        }
        
        $file = $_FILES['blogger_xml'];
        
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error_message = self::get_upload_error_message($file['error']);
            wp_redirect(admin_url('tools.php?page=blogger-import-os&bio_import_error=1&message=' . urlencode($error_message)));
            exit;
        }
        
        // Validate file type
        $file_type = wp_check_filetype_and_ext($file['tmp_name'], $file['name'], array('xml' => 'text/xml'));
        if (empty($file_type['ext']) || empty($file_type['type'])) {
            wp_redirect(admin_url('tools.php?page=blogger-import-os&bio_import_error=1&message=' . urlencode(__('Invalid file type. Please upload an XML file.', 'blogger-import-opensource'))));
            exit;
        }
        
        // Move uploaded file to temporary directory
        $upload_dir = wp_upload_dir();
        $target_file = BIO_TEMP_DIR . 'blogger-import-' . time() . '.xml';
        
        if (!move_uploaded_file($file['tmp_name'], $target_file)) {
            wp_redirect(admin_url('tools.php?page=blogger-import-os&bio_import_error=1&message=' . urlencode(__('Failed to move uploaded file.', 'blogger-import-opensource'))));
            exit;
        }
        
        // Start the import process
        $options = array(
            'import_media' => isset($_POST['import_media']) && $_POST['import_media'] == '1',
            'file_path' => $target_file
        );
        
        // Set initial progress
        BIO_DB_Handler::update_import_progress(array(
            'step' => 'parsing_xml',
            'current' => 0,
            'total' => 100,
            'percentage' => 0,
            'message' => __('Parsing XML file...', 'blogger-import-opensource'),
            'options' => $options
        ));
        
        // Schedule the import process
        wp_schedule_single_event(time(), 'bio_process_import', array($options));
        
        // Redirect to the import page to show progress
        wp_redirect(admin_url('tools.php?page=blogger-import-os'));
        exit;
    }
    
    /**
     * Handle download mapping request
     */
    public static function handle_download_mapping() {
        // Check permissions
        if (!current_user_can('import')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'blogger-import-opensource'));
        }
        
        // Verify nonce
        if (
            !isset($_GET['_wpnonce']) || 
            !wp_verify_nonce($_GET['_wpnonce'], 'bio_download_mapping')
        ) {
            wp_die(__('Security check failed.', 'blogger-import-opensource'));
        }
        
        // Get format
        $format = isset($_GET['format']) ? sanitize_text_field($_GET['format']) : 'csv';
        
        // Handle download
        BIO_Mapping_Exporter::download($format);
    }
    
    /**
     * Get upload error message
     *
     * @param int $error_code Error code
     * @return string         Error message
     */
    private static function get_upload_error_message($error_code) {
        switch ($error_code) {
            case UPLOAD_ERR_INI_SIZE:
                return __('The uploaded file exceeds the upload_max_filesize directive in php.ini.', 'blogger-import-opensource');
            case UPLOAD_ERR_FORM_SIZE:
                return __('The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form.', 'blogger-import-opensource');
            case UPLOAD_ERR_PARTIAL:
                return __('The uploaded file was only partially uploaded.', 'blogger-import-opensource');
            case UPLOAD_ERR_NO_FILE:
                return __('No file was uploaded.', 'blogger-import-opensource');
            case UPLOAD_ERR_NO_TMP_DIR:
                return __('Missing a temporary folder.', 'blogger-import-opensource');
            case UPLOAD_ERR_CANT_WRITE:
                return __('Failed to write file to disk.', 'blogger-import-opensource');
            case UPLOAD_ERR_EXTENSION:
                return __('A PHP extension stopped the file upload.', 'blogger-import-opensource');
            default:
                return __('Unknown upload error.', 'blogger-import-opensource');
        }
    }
}

// Initialize admin
BIO_Admin::init();

/**
 * Process the import
 *
 * @param array $options Import options
 * @return void
 */
function bio_process_import($options) {
    // Check if an import is already running
    if (!BIO_DB_Handler::get_import_progress()) {
        return;
    }
    
    try {
        $file_path = $options['file_path'];
        $import_media = isset($options['import_media']) ? $options['import_media'] : true;
        
        // Check if file exists
        if (!file_exists($file_path)) {
            throw new Exception(__('Import file not found.', 'blogger-import-opensource'));
        }
        
        // Parse XML file
        BIO_DB_Handler::update_import_progress(array(
            'step' => 'parsing_xml',
            'current' => 0,
            'total' => 100,
            'percentage' => 0,
            'message' => __('Parsing XML file...', 'blogger-import-opensource'),
            'options' => $options
        ));
        
        $parse_result = bio_parse_blogger_xml($file_path);
        
        if (is_wp_error($parse_result)) {
            throw new Exception($parse_result->get_error_message());
        }
        
        $data = $parse_result['data'];
        $stats = $parse_result['stats'];
        
        // Import posts
        $post_results = bio_import_posts($data['posts']);
        
        // Import pages
        $page_results = bio_import_posts($data['pages']);
        
        // Collect post mappings for comments
        $post_mapping = array();
        
        // Get all post mappings
        $all_posts = get_posts(array(
            'post_type' => array('post', 'page'),
            'meta_key' => '_bio_blogger_id',
            'posts_per_page' => -1,
            'fields' => 'ids'
        ));
        
        foreach ($all_posts as $post_id) {
            $blogger_id = get_post_meta($post_id, '_bio_blogger_id', true);
            if (!empty($blogger_id)) {
                $post_mapping[$blogger_id] = $post_id;
            }
        }
        
        // Import comments
        $comment_results = bio_import_all_comments($data['comments'], $post_mapping);
        
        // Import media if enabled
        $media_results = array(
            'total' => 0,
            'success' => 0,
            'failed' => 0,
            'skipped' => 0
        );
        
        if ($import_media) {
            // Collect post IDs and their media URLs
            $post_media_map = array();
            
            foreach ($all_posts as $post_id) {
                $blogger_id = get_post_meta($post_id, '_bio_blogger_id', true);
                
                // Find the corresponding entry in the data
                $media_urls = array();
                
                foreach (array_merge($data['posts'], $data['pages']) as $entry) {
                    if ($entry['id'] === $blogger_id && !empty($entry['media_urls'])) {
                        $media_urls = $entry['media_urls'];
                        break;
                    }
                }
                
                if (!empty($media_urls)) {
                    $post_media_map[$post_id] = $media_urls;
                }
            }
            
            // Import all media
            if (!empty($post_media_map)) {
                $media_results = bio_import_all_media($post_media_map);
            }
        }
        
        // Store import stats
        $import_stats = array(
            'date' => current_time('mysql'),
            'posts' => $post_results['success'],
            'pages' => $page_results['success'],
            'comments' => $comment_results['success'],
            'media' => $media_results['success'],
            'total_posts' => count($data['posts']),
            'total_pages' => count($data['pages']),
            'total_comments' => count($data['comments']),
            'total_media' => $media_results['total']
        );
        
        BIO_DB_Handler::store_import_stats($import_stats);
        
        // Clean up temp file
        if (file_exists($file_path)) {
            @unlink($file_path);
        }
        
        // Clear progress
        BIO_DB_Handler::delete_import_progress();
        
        // Set success flag with stats
        update_option('bio_last_import_success', $import_stats);
        
    } catch (Exception $e) {
        // Log error
        BIO_Error_Handler::log_error('Import process failed: ' . $e->getMessage(), 'import_process');
        
        // Clear progress
        BIO_DB_Handler::delete_import_progress();
        
        // Store error for display
        update_option('bio_last_import_error', $e->getMessage());
    }
}
add_action('bio_process_import', 'bio_process_import');

/**
 * Check import progress via AJAX
 */
function bio_ajax_check_progress() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'bio_ajax_nonce')) {
        wp_send_json_error('Invalid nonce');
    }
    
    $progress = BIO_DB_Handler::get_import_progress();
    
    if ($progress) {
        wp_send_json_success($progress);
    } else {
        // Check if there was an error
        $last_error = get_option('bio_last_import_error');
        
        if ($last_error) {
            delete_option('bio_last_import_error');
            wp_send_json_error(array('message' => $last_error));
        }
        
        // Check if there was a success
        $last_success = get_option('bio_last_import_success');
        
        if ($last_success) {
            delete_option('bio_last_import_success');
            wp_send_json_success(array(
                'completed' => true,
                'stats' => $last_success
            ));
        }
        
        wp_send_json_error(array('message' => __('No import in progress.', 'blogger-import-opensource')));
    }
}
add_action('wp_ajax_bio_check_progress', 'bio_ajax_check_progress');

/**
 * Process the import form submission
 */
private function process_import_form() {
    // Verify nonce and other validations...
    
    // Get form data
    $file = $_FILES['blogger_xml'];
    $options = array(
        'skip_media' => isset($_POST['skip_media']),
        'use_current_user' => isset($_POST['use_current_user']), // Add this line
        'create_redirects' => isset($_POST['create_redirects']),
        'redirect_type' => isset($_POST['redirect_type']) ? sanitize_text_field($_POST['redirect_type']) : 'htaccess'
    );
    
    // Rest of your processing code...
}