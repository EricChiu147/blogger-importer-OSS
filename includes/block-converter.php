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
     * @param string $content HTML content
     * @return string         Content in blocks format
     */
    public static function convert_to_blocks($content) {
        // Allow filtering of content before conversion
        $content = apply_filters('bio_pre_convert_to_blocks', $content);
        
        // Clean up the content
        $content = self::clean_content($content);
        
        // Initialize blocks array
        $blocks = array();
        
        // Split content by HTML tags
        $dom = new DOMDocument();
        
        // Suppress warnings for malformed HTML
        libxml_use_internal_errors(true);
        
        // Load HTML with UTF-8 encoding
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $content);
        
        // Clear errors
        libxml_clear_errors();
        
        // Process the DOM
        $body = $dom->getElementsByTagName('body')->item(0);
        
        if ($body) {
            $blocks = self::process_node($body);
        } else {
            // Fallback if DOM parsing failed
            $blocks[] = self::create_paragraph_block($content);
        }
        
        // Allow filtering of blocks after conversion
        $blocks = apply_filters('bio_post_convert_to_blocks', $blocks, $content);
        
        // Convert blocks array to JSON string
        $blocks_json = wp_json_encode($blocks);
        
        return $blocks_json;
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
     * Process a DOM node recursively
     *
     * @param DOMNode $node DOM node
     * @return array        Blocks
     */
    private static function process_node($node) {
        $blocks = array();
        
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                // Text content
                $text = trim($child->nodeValue);
                if (!empty($text)) {
                    $blocks[] = self::create_paragraph_block($text);
                }
            } elseif ($child->nodeType === XML_ELEMENT_NODE) {
                // Element node
                switch ($child->nodeName) {
                    case 'h1':
                    case 'h2':
                    case 'h3':
                    case 'h4':
                    case 'h5':
                    case 'h6':
                        $blocks[] = self::create_heading_block($child);
                        break;
                        
                    case 'p':
                        $blocks[] = self::create_paragraph_block_from_node($child);
                        break;
                        
                    case 'ul':
                        $blocks[] = self::create_list_block($child, 'unordered');
                        break;
                        
                    case 'ol':
                        $blocks[] = self::create_list_block($child, 'ordered');
                        break;
                        
                    case 'blockquote':
                        $blocks[] = self::create_quote_block($child);
                        break;
                        
                    case 'img':
                        $block = self::create_image_block($child);
                        if ($block) {
                            $blocks[] = $block;
                        }
                        break;
                        
                    case 'pre':
                        $blocks[] = self::create_code_block($child);
                        break;
                        
                    case 'table':
                        $blocks[] = self::create_table_block($child);
                        break;
                        
                    case 'figure':
                        $figureBlocks = self::process_figure($child);
                        $blocks = array_merge($blocks, $figureBlocks);
                        break;
                        
                    case 'iframe':
                        $block = self::create_embed_block($child);
                        if ($block) {
                            $blocks[] = $block;
                        }
                        break;
                        
                    case 'div':
                        // Process div content recursively
                        $div_blocks = self::process_node($child);
                        $blocks = array_merge($blocks, $div_blocks);
                        break;
                        
                    default:
                        // For other elements, process children recursively
                        $child_blocks = self::process_node($child);
                        $blocks = array_merge($blocks, $child_blocks);
                        break;
                }
            }
        }
        
        return $blocks;
    }
    
    /**
     * Create a heading block
     *
     * @param DOMNode $node Heading node
     * @return array        Heading block
     */
    private static function create_heading_block($node) {
        $level = (int) substr($node->nodeName, 1);
        
        return array(
            'blockName' => 'core/heading',
            'attrs' => array(
                'level' => $level
            ),
            'innerBlocks' => array(),
            'innerHTML' => $node->nodeValue,
            'innerContent' => array($node->nodeValue)
        );
    }
    
    /**
     * Create a paragraph block from text
     *
     * @param string $text Text content
     * @return array       Paragraph block
     */
    private static function create_paragraph_block($text) {
        return array(
            'blockName' => 'core/paragraph',
            'attrs' => array(),
            'innerBlocks' => array(),
            'innerHTML' => '<p>' . $text . '</p>',
            'innerContent' => array('<p>' . $text . '</p>')
        );
    }
    
    /**
     * Create a paragraph block from a node
     *
     * @param DOMNode $node Paragraph node
     * @return array        Paragraph block
     */
    private static function create_paragraph_block_from_node($node) {
        // Get the HTML content of the paragraph
        $html = self::get_inner_html($node);
        
        return array(
            'blockName' => 'core/paragraph',
            'attrs' => array(),
            'innerBlocks' => array(),
            'innerHTML' => '<p>' . $html . '</p>',
            'innerContent' => array('<p>' . $html . '</p>')
        );
    }
    
    /**
     * Create a list block
     *
     * @param DOMNode $node List node
     * @param string  $type List type (ordered/unordered)
     * @return array        List block
     */
    private static function create_list_block($node, $type) {
        // Get the HTML content of the list
        $html = self::get_inner_html($node);
        
        $block_type = $type === 'ordered' ? 'core/list-item' : 'core/list';
        
        // Create inner blocks for each list item
        $inner_blocks = array();
        
        $list_items = $node->getElementsByTagName('li');
        foreach ($list_items as $item) {
            $inner_blocks[] = array(
                'blockName' => 'core/list-item',
                'attrs' => array(),
                'innerBlocks' => array(),
                'innerHTML' => $item->nodeValue,
                'innerContent' => array($item->nodeValue)
            );
        }
        
        $tag = $type === 'ordered' ? 'ol' : 'ul';
        
        return array(
            'blockName' => $block_type,
            'attrs' => array(
                'ordered' => $type === 'ordered'
            ),
            'innerBlocks' => $inner_blocks,
            'innerHTML' => '<' . $tag . '>' . $html . '</' . $tag . '>',
            'innerContent' => array('<' . $tag . '>', null, '</' . $tag . '>')
        );
    }
    
    /**
     * Create a quote block
     *
     * @param DOMNode $node Quote node
     * @return array        Quote block
     */
    private static function create_quote_block($node) {
        // Get the HTML content of the quote
        $html = self::get_inner_html($node);
        
        return array(
            'blockName' => 'core/quote',
            'attrs' => array(),
            'innerBlocks' => array(),
            'innerHTML' => '<blockquote>' . $html . '</blockquote>',
            'innerContent' => array('<blockquote>' . $html . '</blockquote>')
        );
    }
    
    /**
     * Create an image block
     *
     * @param DOMNode $node Image node
     * @return array|false  Image block or false if not valid
     */
    private static function create_image_block($node) {
        $src = $node->getAttribute('src');
        
        if (empty($src)) {
            return false;
        }
        
        $alt = $node->getAttribute('alt') ?: '';
        $caption = '';
        
        // Check if there's a parent figure with figcaption
        $parent = $node->parentNode;
        if ($parent && $parent->nodeName === 'figure') {
            $captions = $parent->getElementsByTagName('figcaption');
            if ($captions->length > 0) {
                $caption = $captions->item(0)->nodeValue;
            }
        }
        
        $block = array(
            'blockName' => 'core/image',
            'attrs' => array(
                'url' => $src,
                'alt' => $alt,
            ),
            'innerBlocks' => array(),
            'innerHTML' => '<figure class="wp-block-image"><img src="' . esc_url($src) . '" alt="' . esc_attr($alt) . '"/></figure>',
            'innerContent' => array('<figure class="wp-block-image"><img src="' . esc_url($src) . '" alt="' . esc_attr($alt) . '"/></figure>')
        );
        
        // Add caption if exists
        if (!empty($caption)) {
            $block['attrs']['caption'] = $caption;
            $block['innerHTML'] = '<figure class="wp-block-image"><img src="' . esc_url($src) . '" alt="' . esc_attr($alt) . '"/><figcaption>' . $caption . '</figcaption></figure>';
            $block['innerContent'] = array('<figure class="wp-block-image"><img src="' . esc_url($src) . '" alt="' . esc_attr($alt) . '"/><figcaption>' . $caption . '</figcaption></figure>');
        }
        
        return $block;
    }
    
    /**
     * Create a code block
     *
     * @param DOMNode $node Code node
     * @return array        Code block
     */
    private static function create_code_block($node) {
        // Get the text content
        $code = $node->textContent;
        
        return array(
            'blockName' => 'core/code',
            'attrs' => array(),
            'innerBlocks' => array(),
            'innerHTML' => '<pre><code>' . esc_html($code) . '</code></pre>',
            'innerContent' => array('<pre><code>' . esc_html($code) . '</code></pre>')
        );
    }
    
    /**
     * Create a table block
     *
     * @param DOMNode $node Table node
     * @return array        Table block
     */
    private static function create_table_block($node) {
        // Get the HTML content of the table
        $html = self::get_inner_html($node);
        
        return array(
            'blockName' => 'core/table',
            'attrs' => array(),
            'innerBlocks' => array(),
            'innerHTML' => '<figure class="wp-block-table"><table>' . $html . '</table></figure>',
            'innerContent' => array('<figure class="wp-block-table"><table>' . $html . '</table></figure>')
        );
    }
    
    /**
     * Process a figure element
     *
     * @param DOMNode $node Figure node
     * @return array        Blocks
     */
    private static function process_figure($node) {
        $blocks = array();
        
        // Check for image
        $images = $node->getElementsByTagName('img');
        if ($images->length > 0) {
            $blocks[] = self::create_image_block($images->item(0));
            return $blocks;
        }
        
        // Check for iframe (embed)
        $iframes = $node->getElementsByTagName('iframe');
        if ($iframes->length > 0) {
            $block = self::create_embed_block($iframes->item(0));
            if ($block) {
                $blocks[] = $block;
            }
            return $blocks;
        }
        
        // Process as generic content
        return self::process_node($node);
    }
    
    /**
     * Create an embed block
     *
     * @param DOMNode $node Iframe node
     * @return array|false  Embed block or false if not valid
     */
    private static function create_embed_block($node) {
        $src = $node->getAttribute('src');
        
        if (empty($src)) {
            return false;
        }
        
        // Determine provider based on URL
        $provider = '';
        
        if (strpos($src, 'youtube.com') !== false || strpos($src, 'youtu.be') !== false) {
            $provider = 'youtube';
        } elseif (strpos($src, 'vimeo.com') !== false) {
            $provider = 'vimeo';
        }
        
        $block_name = !empty($provider) ? 'core-embed/' . $provider : 'core/embed';
        
        return array(
            'blockName' => $block_name,
            'attrs' => array(
                'url' => $src,
                'providerNameSlug' => $provider,
                'type' => 'rich',
                'responsive' => true
            ),
            'innerBlocks' => array(),
            'innerHTML' => '<figure class="wp-block-embed-' . esc_attr($provider) . ' wp-block-embed is-type-rich is-provider-' . esc_attr($provider) . '"><div class="wp-block-embed__wrapper">' . $src . '</div></figure>',
            'innerContent' => array('<figure class="wp-block-embed-' . esc_attr($provider) . ' wp-block-embed is-type-rich is-provider-' . esc_attr($provider) . '"><div class="wp-block-embed__wrapper">' . $src . '</div></figure>')
        );
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
 * @return string         JSON string of blocks
 */
function bio_convert_to_blocks($content) {
    return BIO_Block_Converter::convert_to_blocks($content);
}