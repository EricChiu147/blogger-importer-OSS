<?php
/**
 * Block Converter for Blogger Import Open Source
 *
 * 將 Blogger 匯入的 HTML 轉成 Gutenberg 區塊。
 *
 * @package Blogger_Import_OpenSource
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

class BIO_Block_Converter {

    /**
     * 將 HTML 內容轉成 Gutenberg 區塊序列化字串
     *
     * @param  string $content HTML
     * @return string          <!-- wp:... --> 形式的字串
     */
    public static function convert_to_blocks( $content ) {

        // 先跑自訂編碼修正
        $content = function_exists( 'bio_fix_encoding' ) ? bio_fix_encoding( $content ) : $content;

        // 允許外掛／佈景前置處理
        $content = apply_filters( 'bio_pre_convert_to_blocks', $content );

        // 基本清理
        $content = self::clean_content( $content );

        // DOM 解析
        libxml_use_internal_errors( true );
        $dom = new DOMDocument();
        $dom->loadHTML( '<?xml encoding="utf-8"?>' . $content );
        libxml_clear_errors();

        $blocks = array();
        $body   = $dom->getElementsByTagName( 'body' )->item( 0 );

        if ( $body ) {
            $blocks = self::process_node( $body );
        } else {
            $blocks[] = self::create_paragraph_block( $content );
        }

        // 後置過濾
        $blocks = apply_filters( 'bio_post_convert_to_blocks', $blocks, $content );

        // 轉成 Gutenberg 序列化格式
        return serialize_blocks( $blocks );
    }

    /** --------- Helpers ---------- */

    private static function clean_content( $content ) {

        $content = preg_replace( '/<div class="blogger-post-footer">.*?<\/div>/s', '', $content );
        $content = preg_replace( '/<div class="blogger-comment-from">.*?<\/div>/s', '', $content );
        $content = force_balance_tags( $content );
        $content = preg_replace( '/<p>\s*<\/p>/i', '', $content );

        return $content;
    }

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

                    case 'h1':
                    case 'h2':
                    case 'h3':
                    case 'h4':
                    case 'h5':
                    case 'h6':
                        $blocks[] = self::create_heading_block( $child );
                        break;

                    case 'p':
                        $blocks[] = self::create_paragraph_block_from_node( $child );
                        break;

                    case 'ul':
                        $blocks[] = self::create_list_block( $child, 'unordered' );
                        break;

                    case 'ol':
                        $blocks[] = self::create_list_block( $child, 'ordered' );
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
                        $blocks[] = self::create_table_block( $child );
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

    /** --------- Block creators ---------- */

    private static function create_heading_block( $node ) {

        $level = (int) substr( $node->nodeName, 1 );

        return array(
            'blockName'    => 'core/heading',
            'attrs'        => array( 'level' => $level ),
            'innerBlocks'  => array(),
            'innerHTML'    => $node->nodeValue,
            'innerContent' => array( $node->nodeValue ),
        );
    }

    private static function create_paragraph_block( $text ) {

        $text = wp_kses_post( $text );

        return array(
            'blockName'    => 'core/paragraph',
            'attrs'        => array(),
            'innerBlocks'  => array(),
            'innerHTML'    => '<p>' . $text . '</p>',
            'innerContent' => array( $text ),
        );
    }

    private static function create_paragraph_block_from_node( $node ) {

        $html = wp_kses_post( self::get_inner_html( $node ) );

        return array(
            'blockName'    => 'core/paragraph',
            'attrs'        => array(),
            'innerBlocks'  => array(),
            'innerHTML'    => '<p>' . $html . '</p>',
            'innerContent' => array( $html ),
        );
    }

    /**
     * Create a list block
     *
     * @param  DOMNode $node <ul> 或 <ol>
     * @param  string  $type 'ordered' / 'unordered'
     * @return array         List block
     */
    private static function create_list_block( $node, $type ) {

        $html = self::get_inner_html( $node );
        $tag  = $type === 'ordered' ? 'ol' : 'ul';

        $inner_content = array();
        foreach ( $node->getElementsByTagName( 'li' ) as $li ) {
            $inner_content[] = '<li>' . self::get_inner_html( $li ) . '</li>';
        }

        return array(
            'blockName'    => 'core/list',
            'attrs'        => array( 'ordered' => ( $type === 'ordered' ) ),
            'innerBlocks'  => array(),
            'innerHTML'    => '<' . $tag . '>' . $html . '</' . $tag . '>',
            'innerContent' => $inner_content,
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
        if ( ! $src ) {
            return false;
        }

        $alt     = $node->getAttribute( 'alt' ) ?: '';
        $caption = '';

        if ( $node->parentNode && $node->parentNode->nodeName === 'figure' ) {
            $caps = $node->parentNode->getElementsByTagName( 'figcaption' );
            if ( $caps->length ) {
                $caption = $caps->item( 0 )->nodeValue;
            }
        }

        $block = array(
            'blockName'    => 'core/image',
            'attrs'        => array(
                'url' => $src,
                'alt' => $alt,
            ),
            'innerBlocks'  => array(),
            'innerHTML'    => '<figure class="wp-block-image"><img src="' . esc_url( $src ) . '" alt="' . esc_attr( $alt ) . '"/></figure>',
            'innerContent' => array(
                '<figure class="wp-block-image"><img src="' . esc_url( $src ) . '" alt="' . esc_attr( $alt ) . '"/></figure>',
            ),
        );

        if ( $caption !== '' ) {
            $block['attrs']['caption'] = $caption;
            $block['innerHTML']        = '<figure class="wp-block-image"><img src="' . esc_url( $src ) . '" alt="' . esc_attr( $alt ) . '"/><figcaption>' . $caption . '</figcaption></figure>';
            $block['innerContent']      = array(
                '<figure class="wp-block-image"><img src="' . esc_url( $src ) . '" alt="' . esc_attr( $alt ) . '"/><figcaption>' . $caption . '</figcaption></figure>',
            );
        }

        return $block;
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
            'innerContent' => array(
                '<figure class="wp-block-table"><table>' . $html . '</table></figure>',
            ),
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
        if ( ! $src ) {
            return false;
        }

        $provider = '';
        if ( str_contains( $src, 'youtube' ) || str_contains( $src, 'youtu.be' ) ) {
            $provider = 'youtube';
        } elseif ( str_contains( $src, 'vimeo.com' ) ) {
            $provider = 'vimeo';
        }

        $block_name = $provider ? "core-embed/$provider" : 'core/embed';

        return array(
            'blockName'    => $block_name,
            'attrs'        => array(
                'url'              => $src,
                'providerNameSlug' => $provider,
                'type'             => 'rich',
                'responsive'       => true,
            ),
            'innerBlocks'  => array(),
            'innerHTML'    => '<figure class="wp-block-embed-' . esc_attr( $provider ) . ' wp-block-embed is-type-rich is-provider-' . esc_attr( $provider ) . '"><div class="wp-block-embed__wrapper">' . esc_url( $src ) . '</div></figure>',
            'innerContent' => array(
                '<figure class="wp-block-embed-' . esc_attr( $provider ) . ' wp-block-embed is-type-rich is-provider-' . esc_attr( $provider ) . '"><div class="wp-block-embed__wrapper">' . esc_url( $src ) . '</div></figure>',
            ),
        );
    }

    private static function get_inner_html( $node ) {

        $html = '';
        foreach ( $node->childNodes as $child ) {
            $html .= $node->ownerDocument->saveHTML( $child );
        }
        return $html;
    }
}

/**
 * 外部呼叫入口
 *
 * @param  string $content HTML
 * @return string          Gutenberg 序列化內容
 */
function bio_convert_to_blocks( $content ) {
    return BIO_Block_Converter::convert_to_blocks( $content );
}
