<?php
/**
 * Plugin Name: Blogger Import Open Source
 * Plugin URI: https://github.com/EricChiu147/blogger-import-opensource
 * Description: A WordPress plugin that imports content from Blogger/Blogspot blogs, including posts, pages, comments, and media.
 * Version: 1.0.0
 * Author: EricChiu147
 * Author URI: https://github.com/EricChiu147
 * Text Domain: blogger-import-opensource
 * Domain Path: /languages
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * 
 * Created: 2025-05-08 15:44:20
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Define plugin constants
define('BIO_VERSION', '1.0.0');
define('BIO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('BIO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('BIO_TEMP_DIR', BIO_PLUGIN_DIR . 'uploads/bio-temp/');

// Make sure the temporary directory exists
if (!file_exists(BIO_TEMP_DIR)) {
    wp_mkdir_p(BIO_TEMP_DIR);
}

// Include required files
require_once BIO_PLUGIN_DIR . 'includes/xml-parser.php';
require_once BIO_PLUGIN_DIR . 'includes/post-importer.php';
require_once BIO_PLUGIN_DIR . 'includes/comment-importer.php';
require_once BIO_PLUGIN_DIR . 'includes/tag-handler.php';
require_once BIO_PLUGIN_DIR . 'includes/block-converter.php';
require_once BIO_PLUGIN_DIR . 'includes/mapping-exporter.php';
require_once BIO_PLUGIN_DIR . 'includes/media-handler.php';
require_once BIO_PLUGIN_DIR . 'includes/db-handler.php';
require_once BIO_PLUGIN_DIR . 'includes/error-handler.php';
require_once BIO_PLUGIN_DIR . 'includes/utils.php';

// Include CLI command if WP-CLI is available
if (defined('WP_CLI') && WP_CLI) {
    require_once BIO_PLUGIN_DIR . 'includes/cli-command.php';
}

// Include admin functionality
if (is_admin()) {
    require_once BIO_PLUGIN_DIR . 'admin/settings-page.php';
}

/**
 * Activation hook
 */
function bio_activate() {
    // Create temporary directory
    if (!file_exists(BIO_TEMP_DIR)) {
        wp_mkdir_p(BIO_TEMP_DIR);
    }
    
    // Add a .htaccess file to protect the temp directory
    $htaccess_content = "Order deny,allow\nDeny from all";
    file_put_contents(BIO_TEMP_DIR . '.htaccess', $htaccess_content);
    
    // Create an index.php file to prevent directory browsing
    file_put_contents(BIO_TEMP_DIR . 'index.php', '<?php // Silence is golden.');
    
    // Initialize plugin version in options
    add_option('bio_plugin_version', BIO_VERSION);
}
register_activation_hook(__FILE__, 'bio_activate');

/**
 * Deactivation hook
 */
function bio_deactivate() {
    // Cleanup tasks if needed
}
register_deactivation_hook(__FILE__, 'bio_deactivate');

/**
 * Load plugin text domain for translations
 */
function bio_load_textdomain() {
    load_plugin_textdomain('blogger-import-opensource', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('plugins_loaded', 'bio_load_textdomain');
