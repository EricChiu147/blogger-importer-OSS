<?php
/**
 * Block Converter for Blogger Import Open Source
 *
 * @package Blogger_Import_OpenSource
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

class BIO_Block_Converter {

    /* =================== Entrance =================== */
    public static function convert_to_blocks( $content ) {

        $content = function_exists( 'bio_fix_encoding' ) ? bio_fix_encoding( $content ) : $content;
        $content = apply_filters( 'bio_pre_convert_to_blocks', $content );
        $content = self::clean_content( $content );

        libxml_use_internal_errors( true );
        $dom = new DOMDocument();
        $dom->loadHTML( '<?xml encoding="utf-8"?>' . $content );
        libxml_clear_errors();

        $body   = $dom->getElementsByTagName( 'body' )->item( 0 );
        $blocks = $body ? self::process_node( $body ) : array( self::create_paragraph_block( $content ) );

        $blocks = apply_filters( 'bio_post_convert_to_blocks', $blocks, $content );

        return serialize_blocks( $blocks );
    }

    /* ============== Pre-processing to clean ============== */
    private static function clean_content( $content ) {

        $content = preg_replace( '/<div class="blogger-post-footer">.*?<\/div>/s', '', $content );
        $content = preg_replace( '/<div class="blogger-comment-from">.*?<\/div>/s', '', $content );
        $content = force_balance_tags( $content );
        $content = preg_replace( '/<p>\s*<\/p>/i', '', $content );

        return $content;
    }

    /* ============== DOM traversal ============== */
    private static function process_node( $node ) {

        $blocks = array();

        foreach ( $node->childNodes as $child ) {

            if ( $child->nodeType === XML_TEXT_NODE ) {

                $text = trim( $child->nodeValue );
                if ( $text !== '' ) {
                    $blocks[] = self::create_paragraph_block( $text );
                }

            } elseif ( $child->nodeType === XML_ELEMENT_NODE ) {

                switch ( $child->nodeName ) {

                    /* ---- Headings ---- */
                    case 'h1': case 'h2': case 'h3':
                    case 'h4': case 'h5': case 'h6':
                        $blocks[] = self::create_heading_block( $child );
                        break;

                    /* ---- Paragraph ---- */
                    case 'p':
                        if ( $img_block = self::maybe_image_block_from_p( $child ) ) {
                            $blocks[] = $img_block;
                        } else {
                            $blocks[] = self::create_paragraph_block_from_node( $child );
                        }
                        break;

                    /* in-line elements → paragraph block */
                    case 'b': case 'strong':
                    case 'i': case 'em':
                    case 'u': case 'span':
                        // Obtain its own html, and keep tags like <strong>
                        $html  = self::replace_inline_tags( self::get_outer_html( $child ) );
                        $html  = wp_kses_post( $html );
                        $align = self::detect_align( $child );
                        $attrs = $align ? array( 'align' => $align ) : array();

                        $blocks[] = array(
                            'blockName'    => 'core/paragraph',
                            'attrs'        => $attrs,
                            'innerBlocks'  => array(),
                            'innerHTML'    => '<p>' . $html . '</p>',
                            'innerContent' => array( $html ),
                        );
                        break;

                    /* ---- Lists ---- */
                    case 'ul':
                        $blocks[] = self::create_list_block( $child, false );
                        break;

                    case 'ol':
                        $blocks[] = self::create_list_block( $child, true );
                        break;

                    /* ---- Others ---- */
                    case 'a':
                        if ( $img_block = self::maybe_image_block_from_anchor( $child ) ) {
                            $blocks[] = $img_block;          // → image blocks (decide whether to keep link base on maybe_image_block_from_anchor())
                        } else {
                            /* Put <a> out of the content */
                            $html  = self::replace_inline_tags( self::get_outer_html( $child ) );
                            $html  = wp_kses_post( $html );
                            $align = self::detect_align( $child );
                            $attrs = $align ? array( 'align' => $align ) : array();
                            $blocks[] = array(
                                'blockName'    => 'core/paragraph',
                                'attrs'        => $attrs,
                                'innerBlocks'  => array(),
                                'innerHTML'    => '<p>' . $html . '</p>',
                                'innerContent' => array( $html ),
                            );
                        }
                        break;
                    
                    case 'blockquote':
                        $blocks[] = self::create_quote_block( $child );
                        break;

                    case 'img':
                        if ( $b = self::create_image_block( $child ) ) {
                            $blocks[] = $b;
                        }
                        break;

                    case 'pre':
                        $blocks[] = self::create_code_block( $child );
                        break;

                    case 'table':
                        if ( $img = self::maybe_image_block_from_table( $child ) ) {
                            $blocks[] = $img;
                        } else {
                            $blocks[] = self::create_table_block( $child );
                        }
                        break;

                    case 'figure':
                        $blocks = array_merge( $blocks, self::process_figure( $child ) );
                        break;

                    case 'iframe':
                        if ( $b = self::create_embed_block( $child ) ) {
                            $blocks[] = $b;
                        }
                        break;

                    case 'div':
                        $blocks = array_merge( $blocks, self::process_node( $child ) );
                        break;

                    default:
                        $blocks = array_merge( $blocks, self::process_node( $child ) );
                        break;
                }
            }
        }

        return $blocks;
    }

    /* ============== Block Builder ============== */

    /* ---- Heading ---- */
    private static function create_heading_block( $node ) {

        $level   = (int) substr( $node->nodeName, 1 );
        $content = wp_kses_post( $node->textContent );
        $html    = "<h{$level} class=\"wp-block-heading\">{$content}</h{$level}>";

        return array(
            'blockName'    => 'core/heading',
            'attrs'        => array( 'level' => $level ),
            'innerBlocks'  => array(),
            'innerHTML'    => $html,
            'innerContent' => array( $html ),
        );
    }

    /* ---- Paragraph ---- */
    private static function replace_inline_tags( $text_or_html ) {
        return str_ireplace(
            array( '<b>', '</b>', '<i>', '</i>' ),
            array( '<strong>', '</strong>', '<em>', '</em>' ),
            $text_or_html
        );
    }

    private static function create_paragraph_block( $text, $align = '' ) {

        $text  = self::replace_inline_tags( $text );
        $text  = wp_kses_post( $text );
        $attrs = array();
        if ( $align ) { $attrs['align'] = $align; }
    
        return array(
            'blockName'    => 'core/paragraph',
            'attrs'        => $attrs,
            'innerBlocks'  => array(),
            'innerHTML'    => '<p>' . $text . '</p>',
            'innerContent' => array( $text ),
        );
    }
    

    private static function create_paragraph_block_from_node( $node ) {

        $html  = self::replace_inline_tags( self::get_inner_html( $node ) );
        $html  = wp_kses_post( $html );
        $align = self::detect_align( $node );      // ★ take alignment settings
        $attrs = array();
        if ( $align ) { $attrs['align'] = $align; }
    
        return array(
            'blockName'    => 'core/paragraph',
            'attrs'        => $attrs,
            'innerBlocks'  => array(),
            'innerHTML'    => '<p>' . $html . '</p>',
            'innerContent' => array( $html ),
        );
    }
    

    /**
     * If <p> only contains one image (can be wrapped by <a>), convert it to image block.
     * Or else, return false
     */
    private static function maybe_image_block_from_p( $p ) {

        /* --- 0. It cannot have elements other than <a>/<img>/<br> --- */
        foreach ( $p->childNodes as $c ) {
            if ( $c->nodeType === XML_ELEMENT_NODE &&
                ! in_array( $c->nodeName, array( 'a', 'img', 'br' ), true ) ) {
                return false;
            }
            if ( $c->nodeType === XML_TEXT_NODE && trim( $c->nodeValue ) !== '' ) {
                return false;        // If there's any text, treat it as a paragraph
            }
        }

        /* --- 1. Get the image node (possibally wrapped by <a>) --- */
        $img = $p->getElementsByTagName( 'img' )->item( 0 );
        if ( ! $img ) {
            return false;
        }
        $src = $img->getAttribute( 'src' );
        if ( ! $src ) {
            return false;
        }
        $alt = $img->getAttribute( 'alt' ) ?: '';

        /* --- 2. Determin alignment --- */
        $align = self::detect_align( $p );   // 會向上追溯 <div style="text-align:…">

        /* --- 3. Return an image block --- */
        return self::build_image_block( $src, $alt, '', $align );
    }

    private static function create_list_block( $node, $ordered ) {
        $tag          = $ordered ? 'ol' : 'ul';
        $inner_blocks = array();
        foreach ( $node->getElementsByTagName( 'li' ) as $li ) {
            $li_html = wp_kses_post( self::get_inner_html( $li ) );
            $inner_blocks[] = array(
                'blockName'    => 'core/list-item',
                'attrs'        => array(),
                'innerBlocks'  => array(),
                'innerHTML'    => '<li>' . $li_html . '</li>',
                'innerContent' => array( $li_html ),
            );
        }
        return array(
            'blockName'    => 'core/list',
            'attrs'        => array( 'ordered' => $ordered ),
            'innerBlocks'  => $inner_blocks,
            'innerHTML'    => "<{$tag} class=\"wp-block-list\"></{$tag}>",
            'innerContent' => array( "<{$tag} class=\"wp-block-list\"></{$tag}>" ),
        );
    }

    private static function create_quote_block( $node ) {
        $html = self::get_inner_html( $node );
        return array(
            'blockName'    => 'core/quote',
            'attrs'        => array(),
            'innerBlocks'  => array(),
            'innerHTML'    => '<blockquote>' . $html . '</blockquote>',
            'innerContent' => array( '<blockquote>' . $html . '</blockquote>' ),
        );
    }

    private static function create_image_block( $node ) {
        $src = $node->getAttribute( 'src' );
        if ( ! $src ) { return false; }
        $alt = $node->getAttribute( 'alt' ) ?: '';
        return self::build_image_block( $src, $alt, '', '' );
    }

    private static function maybe_image_block_from_table( $table ) {
        $rows = $table->getElementsByTagName( 'tr' );
        if ( $rows->length !== 2 ) { return false; }
        $img = $rows->item( 0 )->getElementsByTagName( 'img' )->item( 0 );
        $src = $img ? $img->getAttribute( 'src' ) : '';
        if ( ! $src ) { return false; }
        $alt = $img->getAttribute( 'alt' ) ?: '';
        $caption_td = $rows->item( 1 )->getElementsByTagName( 'td' )->item( 0 );
        $caption    = $caption_td ? trim( $caption_td->textContent ) : '';
        $align = '';
        $td = $rows->item( 0 )->getElementsByTagName( 'td' )->item( 0 );
        if ( $td && $td->hasAttribute( 'style' ) && stripos( $td->getAttribute( 'style' ), 'text-align: center' ) !== false ) {
            $align = 'center';
        }
        return self::build_image_block( $src, $alt, $caption, $align );
    }

    private static function create_code_block( $node ) {
        $code = esc_html( $node->textContent );
        return array(
            'blockName'    => 'core/code',
            'attrs'        => array(),
            'innerBlocks'  => array(),
            'innerHTML'    => '<pre><code>' . $code . '</code></pre>',
            'innerContent' => array( '<pre><code>' . $code . '</code></pre>' ),
        );
    }

    private static function create_table_block( $node ) {
        $html = self::get_inner_html( $node );
        return array(
            'blockName'    => 'core/table',
            'attrs'        => array(),
            'innerBlocks'  => array(),
            'innerHTML'    => '<figure class="wp-block-table"><table>' . $html . '</table></figure>',
            'innerContent' => array('<figure class="wp-block-table"><table>' . $html . '</table></figure>'),
        );
    }

    private static function process_figure( $node ) {
        if ( $node->getElementsByTagName( 'img' )->length ) {
            return array( self::create_image_block( $node->getElementsByTagName( 'img' )->item( 0 ) ) );
        }
        if ( $node->getElementsByTagName( 'iframe' )->length ) {
            if ( $b = self::create_embed_block( $node->getElementsByTagName( 'iframe' )->item( 0 ) ) ) {
                return array( $b );
            }
        }
        return self::process_node( $node );
    }

    private static function create_embed_block( $node ) {
        $src = $node->getAttribute( 'src' );
        if ( ! $src ) { return false; }
        $provider = str_contains( $src, 'youtu' ) ? 'youtube' : ( str_contains( $src, 'vimeo.com' ) ? 'vimeo' : '' );
        $name = $provider ? "core-embed/$provider" : 'core/embed';
        return array(
            'blockName'    => $name,
            'attrs'        => array(
                'url' => $src,
                'providerNameSlug' => $provider,
                'type' => 'rich',
                'responsive' => true,
            ),
            'innerBlocks'  => array(),
            'innerHTML'    => '<figure class="wp-block-embed-' . esc_attr( $provider ) . ' wp-block-embed"><div class="wp-block-embed__wrapper">' . esc_url( $src ) . '</div></figure>',
            'innerContent' => array('<figure class="wp-block-embed-' . esc_attr( $provider ) . ' wp-block-embed"><div class="wp-block-embed__wrapper">' . esc_url( $src ) . '</div></figure>'),
        );
    }

    /* ============== Shared common tools ============== */
    /**
     * Build core/image blocks  
     * — whether to keep the link is decided by $link_dest  
     *
     * @param string $src       image URL (already in local server)
     * @param string $alt       alt text
     * @param string $caption   figcaption text (optional)
     * @param string $align     '', 'center', 'right', 'left'
     * @param string $link_dest 'none' | 'custom'   ← If it is outer link then return custom
     * @param string $href      link_dest = link destination for 'custom' 
     * @return array            Gutenberg block array
     */
    private static function build_image_block(
        $src, $alt, $caption, $align,
        $link_dest = 'none', $href = ''
    ) {

        /* --------- 1. Get attachment ID (if available) --------- */
        $id        = attachment_url_to_postid( $src );
        $size_slug = 'full';              // Fixed full; can be changed depending on the needs
        $img_class = $id ? 'wp-image-' . $id : '';
        $fig_class = 'wp-block-image' .
                    ( $align     ? ' align' . $align       : '' ) .
                    ( $size_slug ? ' size-' . $size_slug   : '' );

        /* --------- 2. figure / img / caption HTML --------- */
        $img_tag  = '<img src="' . esc_url( $src ) . '" alt="' . esc_attr( $alt ) . '"'
                . ( $img_class ? ' class="' . esc_attr( $img_class ) . '"' : '' )
                . '/>';

        // If linkDestination is set to custom, wrap <img> by <a>
        if ( $link_dest === 'custom' && $href ) {
            $img_tag = '<a href="' . esc_url( $href ) . '">' . $img_tag . '</a>';
        }

        $html  = '<figure class="' . esc_attr( $fig_class ) . '">' . $img_tag;
        if ( $caption !== '' ) {
            $html .= '<figcaption class="wp-element-caption"><strong>' .
                    wp_kses_post( $caption ) . '</strong></figcaption>';
        }
        $html .= '</figure>';

        /* --------- 3. block attrs --------- */
        $attrs = array(
            'sizeSlug'        => $size_slug,
            'linkDestination' => $link_dest,      // 'none' 或 'custom'
            'align'           => $align ?: 'none',
            'className'       => trim( 'wp-block-image' . ( $align ? ' align' . $align : '' ) ),
        );

        // If there's an ID, prioritize using id, or else recored the url
        if ( $id ) {
            $attrs['id']  = $id;
        } else {
            $attrs['url'] = $src;
        }

        // Keep custom link if $link_dest is set to custom
        if ( $link_dest === 'custom' && $href ) {
            $attrs['href'] = $href;
        }

        /* --------- 4. Return the block --------- */
        return array(
            'blockName'    => 'core/image',
            'attrs'        => $attrs,
            'innerBlocks'  => array(),
            'innerHTML'    => $html,
            'innerContent' => array( $html ),
        );
    }



    private static function get_inner_html( $node ) {

        $html = '';
        foreach ( $node->childNodes as $child ) {
            $html .= $node->ownerDocument->saveHTML( $child );
        }
        return $html;
    }

    /**
     * Find attributes such as text-align / align from its parent nodes
     * Return '', 'center', 'right', 'left'
     */
    private static function detect_align( $node ) {
        for ( $n = $node; $n && $n->nodeType === XML_ELEMENT_NODE; $n = $n->parentNode ) {

            // style="text-align: …"
            if ( $n->hasAttribute( 'style' ) ) {
                $style = strtolower( $n->getAttribute( 'style' ) );
                if ( strpos( $style, 'text-align' ) !== false ) {
                    if ( strpos( $style, 'center' ) !== false ) return 'center';
                    if ( strpos( $style, 'right'  ) !== false ) return 'right';
                    if ( strpos( $style, 'left'   ) !== false ) return 'left';
                }
            }

            // align="…"
            if ( $n->hasAttribute( 'align' ) ) {
                $align = strtolower( $n->getAttribute( 'align' ) );
                if ( in_array( $align, array( 'center', 'right', 'left' ), true ) ) {
                    return $align;
                }
            }
        }
        return '';
    }


    /**
     * Return an HTML tag that includes itself
     */
    private static function get_outer_html( $node ) {
        $doc = $node->ownerDocument;
        return $doc->saveHTML( $node );
    }

    /**
     * Determin if this image link refers to "click to enlarge the image" by Blogger image hosts
     */
    private static function is_blogger_original_img( $href ) {
        if ( ! $href ) return false;
        $u = wp_parse_url( $href );
        if ( empty( $u['host'] ) ) return false;

        $hosts = array(
            'blogger.googleusercontent.com',
            '1.bp.blogspot.com', '2.bp.blogspot.com',
            '3.bp.blogspot.com', '4.bp.blogspot.com',
        );
        return in_array( $u['host'], $hosts, true ) &&
            preg_match( '~/s\d{2,4}/~', $u['path'] );
    }

    /**
     * <a><img></a> → image block (decide whether to keep link)
     */
    private static function maybe_image_block_from_anchor( $a ) {

        $img = $a->getElementsByTagName( 'img' )->item( 0 );
        if ( ! $img ) return false;

        // No text node in <a> (only <img>)
        if ( trim( $a->textContent ) !== $img->textContent ) return false;

        $src   = $img->getAttribute( 'src' );
        if ( ! $src ) return false;
        $alt   = $img->getAttribute( 'alt' ) ?: '';
        $href  = $a->getAttribute( 'href' );
        $align = self::detect_align( $a );

        /* Determin whether to keep href */
        if ( $href && ! self::is_blogger_original_img( $href ) ) {
            return self::build_image_block( $src, $alt, '', $align, 'custom', $href );
        }
        return self::build_image_block( $src, $alt, '', $align, 'none' );
    }


}

/**
 * Call entrance function to convert HTML to Gutenberg blocks
 *
 * @param  string $content HTML
 * @return string          Gutenberg Serialized blocks
 */
function bio_convert_to_blocks( $content ) {
    return BIO_Block_Converter::convert_to_blocks( $content );
}
