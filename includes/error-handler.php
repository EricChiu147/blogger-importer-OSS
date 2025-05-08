<?php
/**
 * Error Handler for Blogger Import Open Source
 *
 * This file handles error logging and retry mechanisms.
 *
 * @package Blogger_Import_OpenSource
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Class responsible for error handling and retry logic
 */
class BIO_Error_Handler {
    /**
     * Maximum number of retry attempts
     *
     * @var int
     */
    private static $max_retries = 3;
    
    /**
     * Store of retry counts for operations
     *
     * @var array
     */
    private static $retry_counts = array();
    
    /**
     * Log an error message
     *
     * @param string $message Error message
     * @param string $context Error context
     * @param mixed  $data    Additional data for debugging
     * @return void
     */
    public static function log_error($message, $context = '', $data = null) {
        // Log to WordPress debug.log if enabled
        if (defined('WP_DEBUG') && WP_DEBUG === true && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG === true) {
            error_log('[Blogger Import OS] ' . $context . ': ' . $message);
            
            if ($data) {
                error_log('[Blogger Import OS Data] ' . print_r($data, true));
            }
        }
        
        // Store in database
        BIO_DB_Handler::log_error($message, $context);
    }
    
    /**
     * Check if retry is allowed for an operation
     *
     * @param string $operation_key Unique key for the operation
     * @return bool                 True if retry is allowed
     */
    public static function can_retry($operation_key) {
        // Initialize retry count if not exist
        if (!isset(self::$retry_counts[$operation_key])) {
            self::$retry_counts[$operation_key] = 0;
        }
        
        return self::$retry_counts[$operation_key] < self::$max_retries;
    }
    
    /**
     * Increment retry count for an operation
     *
     * @param string $operation_key Unique key for the operation
     * @return int                  New retry count
     */
    public static function increment_retry($operation_key) {
        // Initialize retry count if not exist
        if (!isset(self::$retry_counts[$operation_key])) {
            self::$retry_counts[$operation_key] = 0;
        }
        
        self::$retry_counts[$operation_key]++;
        return self::$retry_counts[$operation_key];
    }
    
    /**
     * Reset retry count for an operation
     *
     * @param string $operation_key Unique key for the operation
     * @return void
     */
    public static function reset_retry($operation_key) {
        self::$retry_counts[$operation_key] = 0;
    }
    
    /**
     * Execute a function with retry logic
     *
     * @param callable $callback     Function to execute
     * @param array    $args         Arguments for the function
     * @param string   $operation_id Unique ID for the operation
     * @param string   $error_context Context for error messages
     * @return mixed                 Result from the function or false on failure
     */
    public static function execute_with_retry($callback, $args = array(), $operation_id = '', $error_context = '') {
        // Generate operation ID if not provided
        if (empty($operation_id)) {
            $operation_id = md5(serialize($callback) . serialize($args));
        }
        
        // Try to execute the function
        while (self::can_retry($operation_id)) {
            try {
                // Call the function
                $result = call_user_func_array($callback, $args);
                
                // If successful, reset retry count and return result
                if ($result !== false) {
                    self::reset_retry($operation_id);
                    return $result;
                }
                
                // If not successful, increment retry count
                $retry_count = self::increment_retry($operation_id);
                self::log_error(
                    sprintf(
                        __('Operation failed, retrying (%d/%d)', 'blogger-import-opensource'),
                        $retry_count,
                        self::$max_retries
                    ),
                    $error_context
                );
                
                // Add a small delay before retrying
                usleep(500000); // 0.5 seconds
                
            } catch (Exception $e) {
                // Log exception
                $retry_count = self::increment_retry($operation_id);
                self::log_error(
                    sprintf(
                        __('Exception: %s. Retrying (%d/%d)', 'blogger-import-opensource'),
                        $e->getMessage(),
                        $retry_count,
                        self::$max_retries
                    ),
                    $error_context,
                    $e->getTraceAsString()
                );
                
                // Add a delay before retrying
                usleep(500000); // 0.5 seconds
            }
        }
        
        // If we've exhausted all retries
        self::log_error(
            __('All retry attempts failed', 'blogger-import-opensource'),
            $error_context
        );
        
        return false;
    }
}