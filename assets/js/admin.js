/**
 * Admin scripts for Blogger Import Open Source
 */
(function($) {
    'use strict';
    
    // Check if an import is running
    if (bioAdmin.importRunning === 'yes') {
        checkImportProgress();
    }
    
    /**
     * Check import progress via AJAX
     */
    function checkImportProgress() {
        $.ajax({
            url: bioAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'bio_check_progress',
                nonce: bioAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Check if completed
                    if (response.data.completed) {
                        displayCompletedMessage(response.data.stats);
                        return;
                    }
                    
                    // Update progress bar
                    updateProgressBar(response.data);
                    
                    // Check again in 3 seconds
                    setTimeout(checkImportProgress, 3000);
                } else {
                    // Error occurred
                    displayErrorMessage(response.data.message);
                }
            },
            error: function() {
                // AJAX error
                displayErrorMessage('AJAX request failed. Please check your connection.');
            }
        });
    }
    
    /**
     * Update progress bar
     */
    function updateProgressBar(progress) {
        $('.bio-progress-bar').css('width', progress.percentage + '%');
        $('.bio-progress-percentage').text(progress.percentage + '%');
        $('.bio-progress-message').text(progress.message);
        $('.bio-progress-details').text('Step: ' + progress.step + ' - Progress: ' + progress.current + ' of ' + progress.total);
    }
    
    /**
     * Display completed message
     */
    function displayCompletedMessage(stats) {
        const message = 'Import completed successfully! Imported: ' + 
                        stats.posts + ' posts, ' + 
                        stats.pages + ' pages, ' + 
                        stats.comments + ' comments, and ' + 
                        stats.media + ' media files.';
        
        $('.bio-import-progress').html(
            '<div class="notice notice-success">' + 
            '<p>' + message + '</p>' + 
            '</div>' + 
            '<p><a href="' + window.location.href.split('?')[0] + '?page=blogger-import-os" class="button button-primary">Refresh Page</a></p>'
        );
    }
    
    /**
     * Display error message
     */
    function displayErrorMessage(message) {
        $('.bio-import-progress').html(
            '<div class="notice notice-error">' + 
            '<p>Import failed: ' + message + '</p>' + 
            '</div>' + 
            '<p><a href="' + window.location.href.split('?')[0] + '?page=blogger-import-os" class="button button-primary">Refresh Page</a></p>'
        );
    }
    
    /**
     * Form validation
     */
    $('#submit').on('click', function(e) {
        const fileInput = $('#blogger-xml');
        
        if (fileInput.length && !fileInput.val()) {
            e.preventDefault();
            alert('Please select a Blogger XML file to import.');
            return false;
        }
        
        $(this).val(bioAdmin.i18n.importing).attr('disabled', true);
        return true;
    });
    
})(jQuery);