<?php
/**
 * Plugin Name: Dify Chat Bot
 * Plugin URI:  https://github.com/ryusei/dify-chat-bot
 * Description: Dify AIと連携するチャットボットウィジェット。管理画面からボタン・ビュワーの位置・色・API設定をカスタマイズ可能。
 * Version:     1.0.1
 * Author:      Ryusei
 * License:     GPL v2 or later
 * Text Domain: dify-chat-bot
 *
 * このファイルの責務：
 * - プラグインのエントリーポイント
 * - 各クラスファイルの読み込み
 * - フロントエンドへのアセット（CSS/JS）のenqueue
 * - 管理画面で設定された値をCSS変数としてフロントに注入
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'DIFY_CHAT_BOT_VERSION', '1.0.1' );
define( 'DIFY_CHAT_BOT_PATH', plugin_dir_path( __FILE__ ) );
define( 'DIFY_CHAT_BOT_URL', plugin_dir_url( __FILE__ ) );

/**
 * アセットの ?ver= 用バージョン文字列を返す
 *
 * 設計意図：
 * プラグイン VERSION 固定値だとファイルを更新してもブラウザ／WPの
 * アセットキャッシュが剥がれない。filemtime() でファイル更新時刻を
 * 取り、ファイル変更のたびに ?ver= を変えてキャッシュを確実に剥がす。
 * filemtime が失敗した（ファイル不存在など）場合はプラグインVERSIONに
 * フォールバックする。
 *
 * @param string $relative_path プラグイン直下からの相対パス（例: assets/js/chat-widget.js）
 * @return string バージョン文字列
 */
function dify_chat_bot_asset_version( $relative_path ) {
    $full_path = DIFY_CHAT_BOT_PATH . $relative_path;
    $mtime     = @filemtime( $full_path );
    return $mtime ? (string) $mtime : DIFY_CHAT_BOT_VERSION;
}

// クラスファイルの読み込み
require_once DIFY_CHAT_BOT_PATH . 'includes/class-admin.php';
require_once DIFY_CHAT_BOT_PATH . 'includes/class-api.php';

/**
 * プラグイン初期化
 * 管理画面クラスとAPIクラスをインスタンス化する
 */
function dify_chat_bot_init() {
    new Dify_Chat_Bot_Admin();
    new Dify_Chat_Bot_Api();
}
add_action( 'plugins_loaded', 'dify_chat_bot_init' );

/**
 * フロントエンド用アセットのenqueue
 * 管理画面以外の全ページでチャットウィジェットのCSS/JSを読み込む
 */
function dify_chat_bot_enqueue_assets() {
    // 管理画面では読み込まない
    if ( is_admin() ) {
        return;
    }

    // API設定が未入力の場合は表示しない
    $api_url = get_option( 'dify_chat_api_url', '' );
    $api_key = get_option( 'dify_chat_api_key', '' );
    if ( empty( $api_url ) || empty( $api_key ) ) {
        return;
    }

    // CSS
    wp_enqueue_style(
        'dify-chat-bot-widget',
        DIFY_CHAT_BOT_URL . 'assets/css/chat-widget.css',
        array(),
        dify_chat_bot_asset_version( 'assets/css/chat-widget.css' )
    );

    // JS
    wp_enqueue_script(
        'dify-chat-bot-widget',
        DIFY_CHAT_BOT_URL . 'assets/js/chat-widget.js',
        array(),
        dify_chat_bot_asset_version( 'assets/js/chat-widget.js' ),
        true
    );

    // JSに渡すデータ（APIエンドポイントとnonce）
    wp_localize_script( 'dify-chat-bot-widget', 'difyChatBot', array(
        'restUrl' => esc_url_raw( rest_url( 'dify-chat-bot/v1/send' ) ),
        'nonce'   => wp_create_nonce( 'wp_rest' ),
    ) );

    // 管理画面の設定値をCSS変数としてインラインCSSで注入
    $css_vars = dify_chat_bot_build_css_vars();
    wp_add_inline_style( 'dify-chat-bot-widget', $css_vars );
}
add_action( 'wp_enqueue_scripts', 'dify_chat_bot_enqueue_assets' );

/**
 * 管理画面の設定値からCSS変数を組み立てる
 *
 * @return string インラインCSS文字列
 */
function dify_chat_bot_build_css_vars() {
    $btn_color        = get_option( 'dify_chat_btn_color', '#0073aa' );
    $btn_text_color   = get_option( 'dify_chat_btn_text_color', '#ffffff' );
    $btn_position     = get_option( 'dify_chat_btn_position', 'bottom-right' );
    $btn_offset_x     = intval( get_option( 'dify_chat_btn_offset_x', 20 ) );
    $btn_offset_y     = intval( get_option( 'dify_chat_btn_offset_y', 20 ) );
    $viewer_header    = get_option( 'dify_chat_viewer_header_color', '#0073aa' );
    $viewer_bg        = get_option( 'dify_chat_viewer_bg_color', '#ffffff' );
    $viewer_width     = intval( get_option( 'dify_chat_viewer_width', 380 ) );
    $viewer_height    = intval( get_option( 'dify_chat_viewer_height', 500 ) );
    $viewer_position  = get_option( 'dify_chat_viewer_position', 'bottom-right' );

    // 位置をCSS変数に変換
    $btn_pos    = dify_chat_bot_position_to_css( $btn_position, $btn_offset_x, $btn_offset_y );
    $viewer_pos = dify_chat_bot_position_to_css( $viewer_position, $btn_offset_x, $btn_offset_y + 70 );

    $css = ":root {\n";
    $css .= "  --dify-btn-color: {$btn_color};\n";
    $css .= "  --dify-btn-text-color: {$btn_text_color};\n";
    $css .= "  --dify-btn-top: {$btn_pos['top']};\n";
    $css .= "  --dify-btn-right: {$btn_pos['right']};\n";
    $css .= "  --dify-btn-bottom: {$btn_pos['bottom']};\n";
    $css .= "  --dify-btn-left: {$btn_pos['left']};\n";
    $css .= "  --dify-viewer-header-color: {$viewer_header};\n";
    $css .= "  --dify-viewer-bg-color: {$viewer_bg};\n";
    $css .= "  --dify-viewer-width: {$viewer_width}px;\n";
    $css .= "  --dify-viewer-height: {$viewer_height}px;\n";
    $css .= "  --dify-viewer-top: {$viewer_pos['top']};\n";
    $css .= "  --dify-viewer-right: {$viewer_pos['right']};\n";
    $css .= "  --dify-viewer-bottom: {$viewer_pos['bottom']};\n";
    $css .= "  --dify-viewer-left: {$viewer_pos['left']};\n";
    $css .= "}\n";

    return $css;
}

/**
 * 位置文字列（bottom-right等）をCSS用のtop/right/bottom/left値に変換
 *
 * @param string $position 位置文字列（bottom-right, bottom-left, top-right, top-left）
 * @param int    $offset_x X方向のオフセット（px）
 * @param int    $offset_y Y方向のオフセット（px）
 * @return array CSS値の連想配列
 */
function dify_chat_bot_position_to_css( $position, $offset_x, $offset_y ) {
    $css = array(
        'top'    => 'auto',
        'right'  => 'auto',
        'bottom' => 'auto',
        'left'   => 'auto',
    );

    switch ( $position ) {
        case 'bottom-right':
            $css['bottom'] = $offset_y . 'px';
            $css['right']  = $offset_x . 'px';
            break;
        case 'bottom-left':
            $css['bottom'] = $offset_y . 'px';
            $css['left']   = $offset_x . 'px';
            break;
        case 'top-right':
            $css['top']   = $offset_y . 'px';
            $css['right'] = $offset_x . 'px';
            break;
        case 'top-left':
            $css['top']  = $offset_y . 'px';
            $css['left'] = $offset_x . 'px';
            break;
        default:
            $css['bottom'] = $offset_y . 'px';
            $css['right']  = $offset_x . 'px';
            break;
    }

    return $css;
}

/**
 * プラグイン有効化時にデフォルトオプションを登録
 */
function dify_chat_bot_activate() {
    $defaults = array(
        'dify_chat_api_url'            => '',
        'dify_chat_api_key'            => '',
        'dify_chat_btn_position'       => 'bottom-right',
        'dify_chat_btn_offset_x'       => 20,
        'dify_chat_btn_offset_y'       => 20,
        'dify_chat_btn_color'          => '#0073aa',
        'dify_chat_btn_text_color'     => '#ffffff',
        'dify_chat_viewer_position'    => 'bottom-right',
        'dify_chat_viewer_width'       => 380,
        'dify_chat_viewer_height'      => 500,
        'dify_chat_viewer_header_color' => '#0073aa',
        'dify_chat_viewer_bg_color'    => '#ffffff',
    );

    foreach ( $defaults as $key => $value ) {
        add_option( $key, $value );
    }
}
register_activation_hook( __FILE__, 'dify_chat_bot_activate' );

/**
 * プラグイン無効化時にオプションを削除
 */
function dify_chat_bot_deactivate() {
    $options = array(
        'dify_chat_api_url',
        'dify_chat_api_key',
        'dify_chat_btn_position',
        'dify_chat_btn_offset_x',
        'dify_chat_btn_offset_y',
        'dify_chat_btn_color',
        'dify_chat_btn_text_color',
        'dify_chat_viewer_position',
        'dify_chat_viewer_width',
        'dify_chat_viewer_height',
        'dify_chat_viewer_header_color',
        'dify_chat_viewer_bg_color',
    );

    foreach ( $options as $key ) {
        delete_option( $key );
    }
}
register_uninstall_hook( __FILE__, 'dify_chat_bot_deactivate' );
