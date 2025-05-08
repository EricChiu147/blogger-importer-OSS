<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package Blogger_Import_OpenSource
 */

// If uninstall not called from WordPress, then exit.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete temporary directory and files
$temp_dir = plugin_dir_path(__FILE__) . 'uploads/bio-temp/';
if (is_dir($temp_dir)) {
    $files = glob($temp_dir . '*', GLOB_MARK);
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($temp_dir);
}

// Delete options
delete_option('bio_plugin_version');
delete_option('bio_import_settings');
