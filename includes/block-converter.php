<?php
/**
 * Block Converter for Blogger Import Open Source
 *
 * This file handles conversion from HTML to Gutenberg blocks.
 *
 * @package Blogger_Import_OpenSource
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Class responsible for converting HTML to Gutenberg blocks
 */
class BIO_Block_Converter {
    /**
     * Convert HTML content to Gutenberg blocks
     *
     * This function converts regular HTML to Gutenberg serialized block format
     * with proper block comments for editor compatibility.
     *
     * @param string $content HTML content
     * @return string         Serialized Gutenberg blocks content
     */
    public static function convert_to_blocks($content) {
        // Apply fix_encoding before conversion
        $content = function_exists('bio_fix_encoding') ? bio_fix_encoding($content) : $content;
        
        // Allow filtering of content before conversion
        $content = apply_filters('bio_pre_convert_to_blocks', $content);
        
        // Clean up the content
        $content = self::clean_content($content);
        
        // If the content is empty, return empty
        if (empty(trim($content))) {
            return '';
        }
        
        // If the content doesn't have HTML tags, just return a simple paragraph block
        if (strpos($content, '<') === false || strpos($content, '>') === false) {
            return '<!-- wp:paragraph --><p>' . $content . '</p><!-- /wp:paragraph -->';
        }
        
        // Split content by HTML tags
        $dom = new DOMDocument();
        
        // Suppress warnings for malformed HTML
        libxml_use_internal_errors(true);
        
        // Load HTML with UTF-8 encoding
        $dom->loadHTML('<?xml encoding="utf-8"?><div>' . $content . '</div>');
        
        // Clear errors
        libxml_clear_errors();
        
        // Process the DOM
        $body = $dom->getElementsByTagName('body')->item(0);
        
        // Initialize the output
        $output = '';
        
        if ($body) {
            // Process body children
            foreach ($body->childNodes as $child) {
                if ($child->nodeType === XML_ELEMENT_NODE && $child->nodeName === 'div') {
                    // Process the main container div's children
                    foreach ($child->childNodes as $contentNode) {
                        $output .= self::process_node_to_block($contentNode);
                    }
                }
            }
        } else {
            // Fallback if DOM parsing failed
            $output = '<!-- wp:paragraph --><p>' . esc_html($content) . '</p><!-- /wp:paragraph -->';
        }
        
        // Allow filtering of content after conversion
        $output = apply_filters('bio_post_convert_to_blocks', $output, $content);
        
        return $output;
    }
    
    /**
     * Clean the HTML content before processing
     *
     * @param string $content HTML content
     * @return string         Cleaned content
     */
    private static function clean_content($content) {
        // Remove blogger-specific markup
        $content = preg_replace('/<div class="blogger-post-footer">.*?<\/div>/s', '', $content);
        $content = preg_replace('/<div class="blogger-comment-from">.*?<\/div>/s', '', $content);
        
        // Fix unclosed tags
        $content = force_balance_tags($content);
        
        // Remove empty paragraphs
        $content = preg_replace('/<p>\s*<\/p>/i', '', $content);
        
        return $content;
    }
    
    /**
     * Process a DOM node to Gutenberg block
     *
     * @param DOMNode $node DOM node
     * @return string       Serialized block markup
     */
    private static function process_node_to_block($node) {
        // Ignore whitespace text nodes
        if ($node->nodeType === XML_TEXT_NODE) {
            $text = trim($node->nodeValue);
            if (empty($text)) {
                return '';
            }
            return self::create_paragraph_block($text);
        }
        
        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return '';
        }
        
        // Process element based on its tag name
        switch ($node->nodeName) {
            case 'h1':
            case 'h2':
            case 'h3':
            case 'h4':
            case 'h5':
            case 'h6':
                return self::create_heading_block($node);
            
            case 'p':
                return self::create_paragraph_block_from_node($node);
            
            case 'ul':
                return self::create_list_block($node, false);
            
            case 'ol':
                return self::create_list_block($node, true);
            
            case 'blockquote':
                return self::create_quote_block($node);
            
            case 'img':
                return self::create_image_block($node);
            
            case 'pre':
                return self::create_code_block($node);
            
            case 'table':
                return self::create_table_block($node);
            
            case 'figure':
                return self::process_figure($node);
            
            case 'iframe':
                return self::create_embed_block($node);
            
            case 'div':
                // Process div content
                $output = '';
                foreach ($node->childNodes as $child) {
                    $output .= self::process_node_to_block($child);
                }
                return $output;
            
            default:
                // For other elements, check if they have children
                if ($node->hasChildNodes()) {
                    $output = '';
                    foreach ($node->childNodes as $child) {
                        $output .= self::process_node_to_block($child);
                    }
                    return $output;
                }
                
                // For empty elements or elements with only text, create a paragraph
                $text = trim($node->textContent);
                if (!empty($text)) {
                    return self::create_paragraph_block($text);
                }
                
                return '';
        }
    }
    
    /**
     * Create a heading block
     *
     * @param DOMNode $node Heading node
     * @return string       Serialized heading block
     */
    private static function create_heading_block($node) {
        $level = (int) substr($node->nodeName, 1);
        $content = esc_html($node->textContent);
        
        return sprintf(
            '<!-- wp:heading {"level":%d} --><h%d>%s</h%d><!-- /wp:heading -->',
            $level,
            $level,
            $content,
            $level
        );
    }
    
    /**
     * Create a paragraph block from text
     *
     * @param string $text Text content
     * @return string      Serialized paragraph block
     */
    private static function create_paragraph_block($text) {
        return sprintf(
            '<!-- wp:paragraph --><p>%s</p><!-- /wp:paragraph -->',
            $text
        );
    }
    
    /**
     * Create a paragraph block from a node
     *
     * @param DOMNode $node Paragraph node
     * @return string       Serialized paragraph block
     */
    private static function create_paragraph_block_from_node($node) {
        // Get the HTML content of the paragraph
        $html = self::get_inner_html($node);
        
        return sprintf(
            '<!-- wp:paragraph --><p>%s</p><!-- /wp:paragraph -->',
            $html
        );
    }
    
    /**
     * Create a list block
     *
     * @param DOMNode $node    List node
     * @param bool    $ordered Whether it's an ordered list
     * @return string          Serialized list block
     */
    private static function create_list_block($node, $ordered) {
        $items = array();
        $list_items = $node->getElementsByTagName('li');
        
        foreach ($list_items as $item) {
            $items[] = trim($item->textContent);
        }
        
        $block_name = $ordered ? 'wp:list {"ordered":true}' : 'wp:list';
        $tag = $ordered ? 'ol' : 'ul';
        $list_items_html = '';
        
        foreach ($items as $item) {
            $list_items_html .= sprintf('<li>%s</li>', $item);
        }
        
        return sprintf(
            '<!-- %s --><ul>%s</ul><!-- /%s -->',
            $block_name,
            $list_items_html,
            'wp:list'
        );
    }
    
    /**
     * Create a quote block
     *
     * @param DOMNode $node Quote node
     * @return string       Serialized quote block
     */
    private static function create_quote_block($node) {
        // Get the HTML content of the quote
        $html = trim($node->textContent);
        
        if (empty($html)) {
            return '';
        }
        
        return sprintf(
            '<!-- wp:quote --><blockquote class="wp-block-quote"><p>%s</p></blockquote><!-- /wp:quote -->',
            $html
        );
    }
    
    /**
     * Create an image block
     *
     * @param DOMNode $node Image node
     * @return string       Serialized image block
     */
    private static function create_image_block($node) {
        $src = $node->getAttribute('src');
        
        if (empty($src)) {
            return '';
        }
        
        $alt = $node->getAttribute('alt') ?: '';
        $caption = '';
        
        // Check if there's a parent figure with figcaption
        $parent = $node->parentNode;
        if ($parent && $parent->nodeName === 'figure') {
            $captions = $parent->getElementsByTagName('figcaption');
            if ($captions->length > 0) {
                $caption = trim($captions->item(0)->textContent);
            }
        }
        
        if (!empty($caption)) {
            return sprintf(
                '<!-- wp:image {"id":"","sizeSlug":"large"} --><figure class="wp-block-image size-large"><img src="%s" alt="%s"/><figcaption>%s</figcaption></figure><!-- /wp:image -->',
                esc_url($src),
                esc_attr($alt),
                esc_html($caption)
            );
        } else {
            return sprintf(
                '<!-- wp:image {"id":"","sizeSlug":"large"} --><figure class="wp-block-image size-large"><img src="%s" alt="%s"/></figure><!-- /wp:image -->',
                esc_url($src),
                esc_attr($alt)
            );
        }
    }
    
    /**
     * Create a code block
     *
     * @param DOMNode $node Code node
     * @return string       Serialized code block
     */
    private static function create_code_block($node) {
        // Get the text content
        $code = trim($node->textContent);
        
        if (empty($code)) {
            return '';
        }
        
        return sprintf(
            '<!-- wp:code --><pre class="wp-block-code"><code>%s</code></pre><!-- /wp:code -->',
            esc_html($code)
        );
    }
    
    /**
     * Create a table block
     *
     * @param DOMNode $node Table node
     * @return string       Serialized table block
     */
    private static function create_table_block($node) {
        // Get the HTML content of the table
        $html = self::get_inner_html($node);
        
        if (empty($html)) {
            return '';
        }
        
        return sprintf(
            '<!-- wp:table --><figure class="wp-block-table"><table>%s</table></figure><!-- /wp:table -->',
            $html
        );
    }
    
    /**
     * Process a figure element
     *
     * @param DOMNode $node Figure node
     * @return string       Serialized block
     */
    private static function process_figure($node) {
        // Check for image
        $images = $node->getElementsByTagName('img');
        if ($images->length > 0) {
            return self::create_image_block($images->item(0));
        }
        
        // Check for iframe (embed)
        $iframes = $node->getElementsByTagName('iframe');
        if ($iframes->length > 0) {
            return self::create_embed_block($iframes->item(0));
        }
        
        // Process as generic content
        $output = '';
        foreach ($node->childNodes as $child) {
            $output .= self::process_node_to_block($child);
        }
        return $output;
    }
    
    /**
     * Create an embed block
     *
     * @param DOMNode $node Iframe node
     * @return string       Serialized embed block
     */
    private static function create_embed_block($node) {
        $src = $node->getAttribute('src');
        
        if (empty($src)) {
            return '';
        }
        
        // Determine provider based on URL
        $provider = '';
        $providerNameSlug = '';
        
        if (strpos($src, 'youtube.com') !== false || strpos($src, 'youtu.be') !== false) {
            $provider = 'YouTube';
            $providerNameSlug = 'youtube';
        } elseif (strpos($src, 'vimeo.com') !== false) {
            $provider = 'Vimeo';
            $providerNameSlug = 'vimeo';
        }
        
        if (!empty($provider)) {
            return sprintf(
                '<!-- wp:embed {"url":"%s","type":"rich","providerNameSlug":"%s","responsive":true} --><figure class="wp-block-embed is-type-rich is-provider-%s wp-block-embed-wordpress wp-embed-aspect-16-9 wp-has-aspect-ratio"><div class="wp-block-embed__wrapper">%s</div></figure><!-- /wp:embed -->',
                esc_url($src),
                $providerNameSlug,
                $providerNameSlug,
                esc_url($src)
            );
        } else {
            return sprintf(
                '<!-- wp:embed {"url":"%s"} --><figure class="wp-block-embed"><div class="wp-block-embed__wrapper">%s</div></figure><!-- /wp:embed -->',
                esc_url($src),
                esc_url($src)
            );
        }
    }
    
    /**
     * Get inner HTML of a node
     *
     * @param DOMNode $node DOM node
     * @return string       Inner HTML
     */
    private static function get_inner_html($node) {
        $html = '';
        
        $children = $node->childNodes;
        foreach ($children as $child) {
            $html .= $node->ownerDocument->saveHTML($child);
        }
        
        return $html;
    }
}

/**
 * Convert HTML content to Gutenberg blocks
 *
 * @param string $content HTML content
 * @return string         Serialized Gutenberg blocks
 */
function bio_convert_to_blocks($content) {
    return BIO_Block_Converter::convert_to_blocks($content);
}