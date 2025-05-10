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

    /* =================== 入口 =================== */
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

    /* ============== 前置清理 ============== */
    private static function clean_content( $content ) {

        $content = preg_replace( '/<div class="blogger-post-footer">.*?<\/div>/s', '', $content );
        $content = preg_replace( '/<div class="blogger-comment-from">.*?<\/div>/s', '', $content );
        $content = force_balance_tags( $content );
        $content = preg_replace( '/<p>\s*<\/p>/i', '', $content );

        return $content;
    }

    /* ============== DOM 遍歷 ============== */
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

                    /* 行內元素整行 → 段落 */
                    case 'b': case 'strong':
                    case 'i': case 'em':
                    case 'u': case 'span':
                        $blocks[] = self::create_paragraph_block_from_node( $child );
                        break;

                    /* ---- Lists ---- */
                    case 'ul':
                        $blocks[] = self::create_list_block( $child, false );
                        break;

                    case 'ol':
                        $blocks[] = self::create_list_block( $child, true );
                        break;

                    /* ---- Others ---- */
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

    /* ============== 區塊建構器 ============== */

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

    private static function create_paragraph_block( $text ) {

        $text = self::replace_inline_tags( $text );
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

        $html = self::replace_inline_tags( self::get_inner_html( $node ) );
        $html = wp_kses_post( $html );

        return array(
            'blockName'    => 'core/paragraph',
            'attrs'        => array(),
            'innerBlocks'  => array(),
            'innerHTML'    => '<p>' . $html . '</p>',
            'innerContent' => array( $html ),
        );
    }

    /* ---- 把 <p><a><img></a></p> 變 image block ---- */
    private static function maybe_image_block_from_p( $p ) {

        // 去掉空白再數子節點
        $imgs   = $p->getElementsByTagName( 'img' );
        $a_tags = $p->getElementsByTagName( 'a' );

        if ( $imgs->length !== 1 ) {
            return false;
        }

        // 確保段落沒有其他文字
        foreach ( $p->childNodes as $c ) {
            if ( $c->nodeType === XML_TEXT_NODE && trim( $c->nodeValue ) !== '' ) {
                return false;
            }
        }

        $img = $imgs->item( 0 );
        $src = $img->getAttribute( 'src' );
        if ( ! $src ) {
            return false;
        }

        $alt   = $img->getAttribute( 'alt' ) ?: '';
        $align = '';

        // Blogger 常用 <a style="margin-left: 1em;margin-right: 1em">
        if ( $p->hasAttribute( 'style' ) && stripos( $p->getAttribute( 'style' ), 'text-align: center' ) !== false ) {
            $align = 'center';
        }
        if ( $a_tags->length && $a_tags->item( 0 )->hasAttribute( 'style' ) ) {
            $style = $a_tags->item( 0 )->getAttribute( 'style' );
            if ( stripos( $style, 'margin-left' ) !== false && stripos( $style, 'margin-right' ) !== false ) {
                $align = 'center';
            }
        }

        return self::build_image_block( $src, $alt, '', $align );
    }

    /* ---- List / Quote / Code / Image / Table / Embed
       (略，與前版相同，請保持原樣)… ---- */
    /*  …保留前一版 create_list_block、create_quote_block、
         create_code_block、maybe_image_block_from_table、
         create_table_block、process_figure、create_embed_block、
         build_image_block、get_inner_html 函式 …  */
    /* ================= 其餘函式維持前版內容 ================= */

    private static function create_list_block( $node, $ordered ) {
        // 與前版相同…
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
        // 與前版相同…
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

    /* ============== 共用工具 ============== */
    private static function build_image_block( $src, $alt, $caption, $align ) {

        $figure_class = 'wp-block-image' . ( $align ? ' align' . $align : '' );

        $html = '<figure class="' . esc_attr( $figure_class ) . '"><img src="' . esc_url( $src ) . '" alt="' . esc_attr( $alt ) . '"/>';
        if ( $caption !== '' ) {
            $html .= '<figcaption class="wp-element-caption"><strong>' . wp_kses_post( $caption ) . '</strong></figcaption>';
        }
        $html .= '</figure>';

        return array(
            'blockName'    => 'core/image',
            'attrs'        => array(
                'url'             => $src,
                'alt'             => $alt,
                'sizeSlug'        => 'full',
                'linkDestination' => 'none',
                'align'           => $align ?: 'none',
            ),
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
