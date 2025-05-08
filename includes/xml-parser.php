<?php
/**
 * XML Parser for Blogger Import Open Source
 *
 * This file handles parsing of the Blogger XML export file and 
 * converts it into structured data for importing.
 *
 * @package Blogger_Import_OpenSource
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Class responsible for parsing Blogger XML export files
 */
class BIO_XML_Parser {
    /**
     * The path to the XML file
     *
     * @var string
     */
    private $xml_file;
    
    /**
     * Error messages
     *
     * @var array
     */
    private $errors = array();
    
    /**
     * Statistics about the parsed content
     *
     * @var array
     */
    private $stats = array(
        'posts' => 0,
        'pages' => 0,
        'comments' => 0,
        'tags' => 0,
        'media' => 0
    );
    
    /**
     * Constructor
     *
     * @param string $file_path Path to the XML file
     */
    public function __construct($file_path) {
        $this->xml_file = $file_path;
    }
    
    /**
     * Parse the XML file
     *
     * @return array|WP_Error Array of parsed data or WP_Error on failure
     */
    public function parse() {
        // Check if file exists
        if (!file_exists($this->xml_file)) {
            $this->errors[] = __('XML file not found.', 'blogger-import-opensource');
            return new WP_Error('file_not_found', __('XML file not found.', 'blogger-import-opensource'));
        }
        
        // Check if file is readable
        if (!is_readable($this->xml_file)) {
            $this->errors[] = __('XML file is not readable.', 'blogger-import-opensource');
            return new WP_Error('file_not_readable', __('XML file is not readable.', 'blogger-import-opensource'));
        }
        
        try {
            // Use XMLReader for memory-efficient parsing
            $reader = new XMLReader();
            $reader->open($this->xml_file);
            
            // Initialize data arrays
            $data = array(
                'posts' => array(),
                'pages' => array(),
                'comments' => array(),
                'tags' => array(),
                'media_urls' => array()
            );
            
            // Parse entries
            while ($reader->read()) {
                if ($reader->nodeType == XMLReader::ELEMENT && $reader->name == 'entry') {
                    $entry_xml = $reader->readOuterXML();
                    $entry = new SimpleXMLElement($entry_xml);
                    
                    // Register namespaces
                    $namespaces = $entry->getNamespaces(true);
                    
                    // Process entry based on type
                    $entry_data = $this->process_entry($entry, $namespaces);
                    
                    if (!empty($entry_data)) {
                        if ($entry_data['type'] == 'post') {
                            $data['posts'][] = $entry_data;
                            $this->stats['posts']++;
                        } elseif ($entry_data['type'] == 'page') {
                            $data['pages'][] = $entry_data;
                            $this->stats['pages']++;
                        } elseif ($entry_data['type'] == 'comment') {
                            $data['comments'][] = $entry_data;
                            $this->stats['comments']++;
                        }
                        
                        // Collect tags
                        if (!empty($entry_data['tags'])) {
                            foreach ($entry_data['tags'] as $tag) {
                                if (!in_array($tag, $data['tags'])) {
                                    $data['tags'][] = $tag;
                                    $this->stats['tags']++;
                                }
                            }
                        }
                        
                        // Collect media URLs
                        if (!empty($entry_data['media_urls'])) {
                            $data['media_urls'] = array_merge($data['media_urls'], $entry_data['media_urls']);
                            $this->stats['media'] += count($entry_data['media_urls']);
                        }
                    }
                }
            }
            
            $reader->close();
            
            // Process post-comment relationships
            $data = $this->process_comment_relationships($data);
            
            return array(
                'data' => $data,
                'stats' => $this->stats
            );
            
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            return new WP_Error('xml_parse_error', $e->getMessage());
        }
    }
    
    /**
     * Process a single entry from the XML
     *
     * @param SimpleXMLElement $entry     Entry element
     * @param array            $namespaces XML namespaces
     * @return array|false                Entry data or false if not processable
     */
    private function process_entry($entry, $namespaces) {
        // Check for required namespaces
        if (!isset($namespaces['thr'])) {
            $namespaces['thr'] = 'http://purl.org/syndication/thread/1.0';
        }
        
        // Get entry type
        $entry_type = $this->get_entry_type($entry);
        
        if ($entry_type == 'post' || $entry_type == 'page') {
            return $this->process_content_entry($entry, $entry_type, $namespaces);
        } elseif ($entry_type == 'comment') {
            return $this->process_comment_entry($entry, $namespaces);
        }
        
        return false;
    }
    
    /**
     * Determine the type of entry (post, page, comment)
     *
     * @param SimpleXMLElement $entry Entry element
     * @return string                 Type of entry
     */
    private function get_entry_type($entry) {
        // Check if it's a comment
        if (isset($entry->xpath('thr:in-reply-to')[0])) {
            return 'comment';
        }
        
        // Check if it's a page or post
        $kind = '';
        foreach ($entry->category as $category) {
            $attributes = $category->attributes();
            if (isset($attributes['scheme']) && $attributes['scheme'] == 'http://schemas.google.com/g/2005#kind') {
                $kind = (string) $attributes['term'];
                break;
            }
        }
        
        if (strpos($kind, '#page') !== false) {
            return 'page';
        }
        
        // Default to post
        return 'post';
    }
    
    /**
     * Process a content entry (post or page)
     *
     * @param SimpleXMLElement $entry      Entry element
     * @param string           $entry_type Type of entry
     * @param array            $namespaces XML namespaces
     * @return array                       Processed entry data
     */
    private function process_content_entry($entry, $entry_type, $namespaces) {
        $data = array(
            'type' => $entry_type,
            'id' => (string) $entry->id,
            'title' => (string) $entry->title,
            'content' => (string) $entry->content,
            'published' => (string) $entry->published,
            'updated' => (string) $entry->updated,
            'author' => array(
                'name' => (string) $entry->author->name,
                'email' => (string) $entry->author->email
            ),
            'tags' => array(),
            'media_urls' => array()
        );
        
        // Get permalink
        foreach ($entry->link as $link) {
            $attributes = $link->attributes();
            if (isset($attributes['rel']) && $attributes['rel'] == 'alternate') {
                $data['permalink'] = (string) $attributes['href'];
                break;
            }
        }
        
        // Get status
        $data['status'] = 'publish'; // Default
        foreach ($entry->category as $category) {
            $attributes = $category->attributes();
            $term = (string) $attributes['term'];
            
            if ($term == 'http://schemas.google.com/blogger/2008/kind#post') {
                // It's a post
            } elseif ($term == 'http://schemas.google.com/blogger/2008/kind#page') {
                // It's a page
            } elseif ($term == 'http://schemas.google.com/blogger/2008/kind#draft') {
                $data['status'] = 'draft';
            } elseif (strpos($term, 'http://www.blogger.com/atom/ns#') === false) {
                // It's a tag/label
                $tag = str_replace('http://www.blogger.com/atom/ns#', '', $term);
                $data['tags'][] = $tag;
            }
        }
        
        // Extract media URLs from content
        $data['media_urls'] = $this->extract_media_urls($data['content']);
        
        return $data;
    }
    
    /**
     * Process a comment entry
     *
     * @param SimpleXMLElement $entry      Entry element
     * @param array            $namespaces XML namespaces
     * @return array                       Processed comment data
     */
    private function process_comment_entry($entry, $namespaces) {
        $thr = $entry->children($namespaces['thr']);
        $in_reply_to = $thr->{'in-reply-to'};
        $ref = (string) $in_reply_to->attributes()->{'ref'};
        
        $data = array(
            'type' => 'comment',
            'id' => (string) $entry->id,
            'content' => (string) $entry->content,
            'published' => (string) $entry->published,
            'updated' => (string) $entry->updated,
            'author' => array(
                'name' => (string) $entry->author->name,
                'email' => isset($entry->author->email) ? (string) $entry->author->email : '',
                'url' => ''
            ),
            'post_id' => $ref,
            'parent_id' => 0 // Will be set later
        );
        
        // Get author URL if present
        foreach ($entry->author->children() as $element) {
            if ($element->getName() == 'uri') {
                $data['author']['url'] = (string) $element;
                break;
            }
        }
        
        return $data;
    }
    
    /**
     * Process comment relationships to establish hierarchy
     *
     * @param array $data Parsed data
     * @return array      Processed data with comment relationships
     */
    private function process_comment_relationships($data) {
        // Create mapping of post ID to comment IDs
        $post_comment_map = array();
        foreach ($data['comments'] as $comment) {
            if (!isset($post_comment_map[$comment['post_id']])) {
                $post_comment_map[$comment['post_id']] = array();
            }
            $post_comment_map[$comment['post_id']][] = $comment['id'];
        }
        
        // Process comments to determine parent-child relationships
        for ($i = 0; $i < count($data['comments']); $i++) {
            $comment = &$data['comments'][$i];
            
            // If the post_id is a comment ID, it's a reply to a comment
            if (in_array($comment['post_id'], array_merge(...array_values($post_comment_map)))) {
                foreach ($data['comments'] as $other_comment) {
                    if ($other_comment['id'] == $comment['post_id']) {
                        $comment['parent_id'] = $other_comment['id'];
                        $comment['post_id'] = $other_comment['post_id'];
                        break;
                    }
                }
            }
        }
        
        return $data;
    }
    
    /**
     * Extract media URLs from content
     *
     * @param string $content HTML content
     * @return array          Found media URLs
     */
    private function extract_media_urls($content) {
        $media_urls = array();
        
        // Extract image URLs
        preg_match_all('/<img[^>]+src="([^"]+)"[^>]*>/i', $content, $img_matches);
        if (isset($img_matches[1])) {
            $media_urls = array_merge($media_urls, $img_matches[1]);
        }
        
        // Extract video URLs
        preg_match_all('/<iframe[^>]+src="([^"]+)"[^>]*>/i', $content, $iframe_matches);
        if (isset($iframe_matches[1])) {
            $media_urls = array_merge($media_urls, $iframe_matches[1]);
        }
        
        // Remove non-media URLs and duplicates
        $media_urls = array_unique($media_urls);
        $filtered_urls = array();
        
        foreach ($media_urls as $url) {
            // Only include URLs from common media domains or with media extensions
            $is_media = false;
            
            // Check common image/media domains
            if (
                strpos($url, 'blogspot.com') !== false ||
                strpos($url, 'blogger.com') !== false ||
                strpos($url, 'bp.blogspot.com') !== false ||
                strpos($url, 'googleusercontent.com') !== false
            ) {
                $is_media = true;
            }
            
            // Check media file extensions
            $media_extensions = array('.jpg', '.jpeg', '.png', '.gif', '.webp', '.svg', '.mp4', '.webm', '.ogg');
            foreach ($media_extensions as $ext) {
                if (strpos($url, $ext) !== false) {
                    $is_media = true;
                    break;
                }
            }
            
            if ($is_media) {
                $filtered_urls[] = $url;
            }
        }
        
        return $filtered_urls;
    }
    
    /**
     * Get parsing errors
     *
     * @return array Array of error messages
     */
    public function get_errors() {
        return $this->errors;
    }
    
    /**
     * Get parsing statistics
     *
     * @return array Statistics about parsed content
     */
    public function get_stats() {
        return $this->stats;
    }
}

/**
 * Process the Blogger XML file
 *
 * @param string $file_path Path to the XML file
 * @return array|WP_Error  Array of parsed data or WP_Error on failure
 */
function bio_parse_blogger_xml($file_path) {
    $parser = new BIO_XML_Parser($file_path);
    return $parser->parse();
}