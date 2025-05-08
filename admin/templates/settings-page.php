<?php
/**
 * Settings Page Template for Blogger Import Open Source
 *
 * @package Blogger_Import_OpenSource
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}
?>

<div class="wrap">
    <h1><?php _e('Blogger Importer', 'blogger-import-opensource'); ?></h1>
    
    <?php if ($import_running): ?>
        <div class="bio-import-progress">
            <h2><?php _e('Import in Progress', 'blogger-import-opensource'); ?></h2>
            
            <div class="bio-progress-container">
                <div class="bio-progress-bar" style="width: <?php echo esc_attr($import_progress['percentage']); ?>%;">
                    <span class="bio-progress-percentage"><?php echo esc_html($import_progress['percentage']); ?>%</span>
                </div>
            </div>
            
            <p class="bio-progress-message">
                <?php echo esc_html($import_progress['message']); ?>
            </p>
            
            <p class="bio-progress-details">
                <?php printf(
                    __('Step: %s - Progress: %d of %d', 'blogger-import-opensource'),
                    esc_html($import_progress['step']),
                    intval($import_progress['current']),
                    intval($import_progress['total'])
                ); ?>
            </p>
            
            <div class="bio-import-notice notice notice-warning inline">
                <p><?php _e('Please do not close this window or navigate away until the import is complete.', 'blogger-import-opensource'); ?></p>
            </div>
        </div>
    <?php else: ?>
        <?php if ($show_stats): ?>
            <div class="bio-import-results">
                <h2><?php _e('Last Import Results', 'blogger-import-opensource'); ?></h2>
                
                <div class="bio-stats-container">
                    <div class="bio-stats-item">
                        <span class="dashicons dashicons-admin-post"></span>
                        <span class="bio-stats-count"><?php echo intval($import_stats['posts']); ?></span>
                        <span class="bio-stats-label"><?php _e('Posts', 'blogger-import-opensource'); ?></span>
                    </div>
                    
                    <div class="bio-stats-item">
                        <span class="dashicons dashicons-admin-page"></span>
                        <span class="bio-stats-count"><?php echo intval($import_stats['pages']); ?></span>
                        <span class="bio-stats-label"><?php _e('Pages', 'blogger-import-opensource'); ?></span>
                    </div>
                    
                    <div class="bio-stats-item">
                        <span class="dashicons dashicons-admin-comments"></span>
                        <span class="bio-stats-count"><?php echo intval($import_stats['comments']); ?></span>
                        <span class="bio-stats-label"><?php _e('Comments', 'blogger-import-opensource'); ?></span>
                    </div>
                    
                    <div class="bio-stats-item">
                        <span class="dashicons dashicons-admin-media"></span>
                        <span class="bio-stats-count"><?php echo intval($import_stats['media']); ?></span>
                        <span class="bio-stats-label"><?php _e('Media', 'blogger-import-opensource'); ?></span>
                    </div>
                </div>
                
                <div class="bio-mapping-export">
                    <h3><?php _e('URL Mapping', 'blogger-import-opensource'); ?></h3>
                    <p><?php _e('Download mapping file to set up redirects from your Blogger URLs to the new WordPress URLs:', 'blogger-import-opensource'); ?></p>
                    
                    <div class="bio-export-buttons">
                        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=bio_download_mapping&format=csv'), 'bio_download_mapping')); ?>" class="button">
                            <?php _e('Download CSV', 'blogger-import-opensource'); ?>
                        </a>
                        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=bio_download_mapping&format=json'), 'bio_download_mapping')); ?>" class="button">
                            <?php _e('Download JSON', 'blogger-import-opensource'); ?>
                        </a>
                        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=bio_download_mapping&format=php'), 'bio_download_mapping')); ?>" class="button">
                            <?php _e('Download PHP Redirects', 'blogger-import-opensource'); ?>
                        </a>
                        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=bio_download_mapping&format=htaccess'), 'bio_download_mapping')); ?>" class="button">
                            <?php _e('Download .htaccess Rules', 'blogger-import-opensource'); ?>
                        </a>
                    </div>
                </div>
            </div>
            
            <hr>
        <?php endif; ?>
        
        <div class="bio-import-form">
            <h2><?php _e('Import Blogger Content', 'blogger-import-opensource'); ?></h2>
            
            <div class="bio-import-instructions">
                <h3><?php _e('Before You Begin', 'blogger-import-opensource'); ?></h3>
                <ol>
                    <li><?php _e('Log in to your Blogger account.', 'blogger-import-opensource'); ?></li>
                    <li><?php _e('Go to Settings > Other > Back up content.', 'blogger-import-opensource'); ?></li>
                    <li><?php _e('Click "Save to your computer" to download the XML file.', 'blogger-import-opensource'); ?></li>
                    <li><?php _e('Upload the XML file using the form below.', 'blogger-import-opensource'); ?></li>
                </ol>
            </div>
            
            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="bio_import">
                <?php wp_nonce_field('bio_import', 'bio_import_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="blogger-xml"><?php _e('Blogger XML File', 'blogger-import-opensource'); ?></label>
                        </th>
                        <td>
                            <input type="file" name="blogger_xml" id="blogger-xml" required>
                            <p class="description">
                                <?php printf(
                                    __('Maximum upload file size: %s', 'blogger-import-opensource'),
                                    $formatted_max_size
                                ); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="import-media"><?php _e('Import Media', 'blogger-import-opensource'); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" name="import_media" id="import-media" value="1" checked>
                                <?php _e('Download and import media files from Blogger', 'blogger-import-opensource'); ?>
                            </label>
                            <p class="description">
                                <?php _e('This will download images and other media from your Blogger posts.', 'blogger-import-opensource'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="submit" id="submit" class="button button-primary" value="<?php _e('Import', 'blogger-import-opensource'); ?>">
                </p>
            </form>
            
            <div class="bio-import-notice notice notice-warning inline">
                <p><?php _e('The import process may take some time depending on the size of your Blogger export and the number of media files.', 'blogger-import-opensource'); ?></p>
            </div>
            
            <div class="bio-cli-info">
                <h3><?php _e('Using WP-CLI for Large Imports', 'blogger-import-opensource'); ?></h3>
                <p><?php _e('For large blogs, you may want to use the WP-CLI command:', 'blogger-import-opensource'); ?></p>
                <pre>wp blogger-import import /path/to/blogger-export.xml</pre>
            </div>
        </div>
    <?php endif; ?>
</div>