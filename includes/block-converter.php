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
                        // 取「含自身」HTML，保留 <strong> 等標籤
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
                            $blocks[] = $img_block;          // → 圖片區塊（依判定決定是否保留連結）
                        } else {
                            /* 原來的段落包 <a> 邏輯 */
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
        $align = self::detect_align( $node );      // ★ 取對齊
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
     * 若 <p> 只包含 1 張圖片（可被 <a> 包住），就轉成 image block
     * 否則回傳 false
     */
    private static function maybe_image_block_from_p( $p ) {

        /* --- 0. 不能有非 <a>/<img>/<br> 以外的元素 --- */
        foreach ( $p->childNodes as $c ) {
            if ( $c->nodeType === XML_ELEMENT_NODE &&
                ! in_array( $c->nodeName, array( 'a', 'img', 'br' ), true ) ) {
                return false;
            }
            if ( $c->nodeType === XML_TEXT_NODE && trim( $c->nodeValue ) !== '' ) {
                return false;        // 有文字就當普通段落
            }
        }

        /* --- 1. 取圖片節點（可能被 <a> 包住） --- */
        $img = $p->getElementsByTagName( 'img' )->item( 0 );
        if ( ! $img ) {
            return false;
        }
        $src = $img->getAttribute( 'src' );
        if ( ! $src ) {
            return false;
        }
        $alt = $img->getAttribute( 'alt' ) ?: '';

        /* --- 2. 判斷置中 / 置右 --- */
        $align = self::detect_align( $p );   // 會向上追溯 <div style="text-align:…">

        /* --- 3. 回傳 image block --- */
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
    /**
     * 組 core/image 區塊  
     * — 可決定是否保留超連結  
     *
     * @param string $src       圖片 URL（已是本地主機）
     * @param string $alt       alt 文字
     * @param string $caption   figcaption 文字（允許空）
     * @param string $align     '', 'center', 'right', 'left'
     * @param string $link_dest 'none' | 'custom'   ← 若是外部連結就傳 custom
     * @param string $href      link_dest = 'custom' 時要帶的網址
     * @return array            Gutenberg block array
     */
    private static function build_image_block(
        $src, $alt, $caption, $align,
        $link_dest = 'none', $href = ''
    ) {

        /* --------- 1. 取附件 ID（若有） --------- */
        $id        = attachment_url_to_postid( $src );
        $size_slug = 'full';              // 這裡固定 full；可視情況取實際尺寸
        $img_class = $id ? 'wp-image-' . $id : '';
        $fig_class = 'wp-block-image' .
                    ( $align     ? ' align' . $align       : '' ) .
                    ( $size_slug ? ' size-' . $size_slug   : '' );

        /* --------- 2. figure / img / caption HTML --------- */
        $img_tag  = '<img src="' . esc_url( $src ) . '" alt="' . esc_attr( $alt ) . '"'
                . ( $img_class ? ' class="' . esc_attr( $img_class ) . '"' : '' )
                . '/>';

        // 若 linkDestination 設 custom，將 <img> 包 <a>
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

        // 有附件 ID 優先用 id，否則記錄 url
        if ( $id ) {
            $attrs['id']  = $id;
        } else {
            $attrs['url'] = $src;
        }

        // 保留自訂連結網址
        if ( $link_dest === 'custom' && $href ) {
            $attrs['href'] = $href;
        }

        /* --------- 4. 回傳區塊 --------- */
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
     * 由自己往父節點找 text-align / align 屬性
     * 回傳 '', 'center', 'right', 'left'
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
     * 回傳包含自身標籤的 HTML（DOMDocument 沒有現成方法，只能手動包）
     */
    private static function get_outer_html( $node ) {
        $doc = $node->ownerDocument;
        return $doc->saveHTML( $node );
    }

    /**
     * 由 Blogger 原圖網址判定「點圖開原尺寸」
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
     * <a><img></a> → image block（決定是否保留連結）
     */
    private static function maybe_image_block_from_anchor( $a ) {

        $img = $a->getElementsByTagName( 'img' )->item( 0 );
        if ( ! $img ) return false;

        // 段落內不能含其他文字
        if ( trim( $a->textContent ) !== $img->textContent ) return false;

        $src   = $img->getAttribute( 'src' );
        if ( ! $src ) return false;
        $alt   = $img->getAttribute( 'alt' ) ?: '';
        $href  = $a->getAttribute( 'href' );
        $align = self::detect_align( $a );

        /* 判斷是否保留 href */
        if ( $href && ! self::is_blogger_original_img( $href ) ) {
            return self::build_image_block( $src, $alt, '', $align, 'custom', $href );
        }
        return self::build_image_block( $src, $alt, '', $align, 'none' );
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
