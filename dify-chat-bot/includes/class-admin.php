<?php
/**
 * Dify Chat Bot 管理画面クラス
 *
 * このファイルの責務：
 * - WordPress管理画面に設定ページを追加
 * - Settings API でAPI設定・ボタン設定・ビュワー設定フィールドを登録
 * - カラーピッカー用のアセットをenqueue
 * - 設定値のサニタイズ処理
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Dify_Chat_Bot_Admin {

    /**
     * コンストラクタ：管理画面用フックを登録
     */
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
    }

    /**
     * 管理メニューにDify Chat Botの設定ページを追加
     */
    public function add_menu_page() {
        add_options_page(
            'Dify Chat Bot 設定',
            'Dify Chat Bot',
            'manage_options',
            'dify-chat-bot',
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * 管理画面用アセットのenqueue
     * カラーピッカーと管理画面用CSSを読み込む
     *
     * @param string $hook 現在の管理画面ページフック
     */
    public function enqueue_admin_assets( $hook ) {
        if ( 'settings_page_dify-chat-bot' !== $hook ) {
            return;
        }

        // WordPress同梱のカラーピッカー
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );

        // 管理画面用CSS
        wp_enqueue_style(
            'dify-chat-bot-admin',
            DIFY_CHAT_BOT_URL . 'assets/css/admin.css',
            array( 'wp-color-picker' ),
            DIFY_CHAT_BOT_VERSION
        );

        // カラーピッカー初期化スクリプト（インライン）
        wp_add_inline_script( 'wp-color-picker', '
            jQuery(document).ready(function($) {
                $(".dify-color-picker").wpColorPicker();
            });
        ' );
    }

    /**
     * Settings API によるフィールド登録
     * 3つのセクション：API設定、ボタン設定、ビュワー設定
     */
    public function register_settings() {
        // --- API設定セクション ---
        add_settings_section(
            'dify_chat_api_section',
            'API設定',
            array( $this, 'render_api_section' ),
            'dify-chat-bot'
        );

        register_setting( 'dify_chat_bot_settings', 'dify_chat_api_url', array(
            'sanitize_callback' => 'esc_url_raw',
            'default'           => '',
        ) );
        add_settings_field(
            'dify_chat_api_url',
            'Dify API URL',
            array( $this, 'render_text_field' ),
            'dify-chat-bot',
            'dify_chat_api_section',
            array(
                'name'        => 'dify_chat_api_url',
                'placeholder' => 'https://api.dify.ai/v1',
                'description' => 'DifyのAPIベースURLを入力してください（例: https://api.dify.ai/v1）',
            )
        );

        register_setting( 'dify_chat_bot_settings', 'dify_chat_api_key', array(
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ) );
        add_settings_field(
            'dify_chat_api_key',
            'API Key',
            array( $this, 'render_password_field' ),
            'dify-chat-bot',
            'dify_chat_api_section',
            array(
                'name'        => 'dify_chat_api_key',
                'placeholder' => 'app-xxxxxxxxxxxxxxxx',
                'description' => 'DifyアプリのAPIキーを入力してください',
            )
        );

        // --- ボタン設定セクション ---
        add_settings_section(
            'dify_chat_btn_section',
            'チャットボタン設定',
            array( $this, 'render_btn_section' ),
            'dify-chat-bot'
        );

        register_setting( 'dify_chat_bot_settings', 'dify_chat_btn_position', array(
            'sanitize_callback' => array( $this, 'sanitize_position' ),
            'default'           => 'bottom-right',
        ) );
        add_settings_field(
            'dify_chat_btn_position',
            '表示位置',
            array( $this, 'render_position_field' ),
            'dify-chat-bot',
            'dify_chat_btn_section',
            array( 'name' => 'dify_chat_btn_position' )
        );

        register_setting( 'dify_chat_bot_settings', 'dify_chat_btn_offset_x', array(
            'sanitize_callback' => 'absint',
            'default'           => 20,
        ) );
        add_settings_field(
            'dify_chat_btn_offset_x',
            'X方向オフセット (px)',
            array( $this, 'render_number_field' ),
            'dify-chat-bot',
            'dify_chat_btn_section',
            array(
                'name' => 'dify_chat_btn_offset_x',
                'min'  => 0,
                'max'  => 500,
            )
        );

        register_setting( 'dify_chat_bot_settings', 'dify_chat_btn_offset_y', array(
            'sanitize_callback' => 'absint',
            'default'           => 20,
        ) );
        add_settings_field(
            'dify_chat_btn_offset_y',
            'Y方向オフセット (px)',
            array( $this, 'render_number_field' ),
            'dify-chat-bot',
            'dify_chat_btn_section',
            array(
                'name' => 'dify_chat_btn_offset_y',
                'min'  => 0,
                'max'  => 500,
            )
        );

        register_setting( 'dify_chat_bot_settings', 'dify_chat_btn_color', array(
            'sanitize_callback' => 'sanitize_hex_color',
            'default'           => '#0073aa',
        ) );
        add_settings_field(
            'dify_chat_btn_color',
            'ボタン背景色',
            array( $this, 'render_color_field' ),
            'dify-chat-bot',
            'dify_chat_btn_section',
            array( 'name' => 'dify_chat_btn_color' )
        );

        register_setting( 'dify_chat_bot_settings', 'dify_chat_btn_text_color', array(
            'sanitize_callback' => 'sanitize_hex_color',
            'default'           => '#ffffff',
        ) );
        add_settings_field(
            'dify_chat_btn_text_color',
            'ボタンアイコン色',
            array( $this, 'render_color_field' ),
            'dify-chat-bot',
            'dify_chat_btn_section',
            array( 'name' => 'dify_chat_btn_text_color' )
        );

        // --- ビュワー設定セクション ---
        add_settings_section(
            'dify_chat_viewer_section',
            'チャットビュワー設定',
            array( $this, 'render_viewer_section' ),
            'dify-chat-bot'
        );

        register_setting( 'dify_chat_bot_settings', 'dify_chat_viewer_position', array(
            'sanitize_callback' => array( $this, 'sanitize_position' ),
            'default'           => 'bottom-right',
        ) );
        add_settings_field(
            'dify_chat_viewer_position',
            '表示位置',
            array( $this, 'render_position_field' ),
            'dify-chat-bot',
            'dify_chat_viewer_section',
            array( 'name' => 'dify_chat_viewer_position' )
        );

        register_setting( 'dify_chat_bot_settings', 'dify_chat_viewer_width', array(
            'sanitize_callback' => 'absint',
            'default'           => 380,
        ) );
        add_settings_field(
            'dify_chat_viewer_width',
            '幅 (px)',
            array( $this, 'render_number_field' ),
            'dify-chat-bot',
            'dify_chat_viewer_section',
            array(
                'name' => 'dify_chat_viewer_width',
                'min'  => 280,
                'max'  => 800,
            )
        );

        register_setting( 'dify_chat_bot_settings', 'dify_chat_viewer_height', array(
            'sanitize_callback' => 'absint',
            'default'           => 500,
        ) );
        add_settings_field(
            'dify_chat_viewer_height',
            '高さ (px)',
            array( $this, 'render_number_field' ),
            'dify-chat-bot',
            'dify_chat_viewer_section',
            array(
                'name' => 'dify_chat_viewer_height',
                'min'  => 300,
                'max'  => 800,
            )
        );

        register_setting( 'dify_chat_bot_settings', 'dify_chat_viewer_header_color', array(
            'sanitize_callback' => 'sanitize_hex_color',
            'default'           => '#0073aa',
        ) );
        add_settings_field(
            'dify_chat_viewer_header_color',
            'ヘッダー色',
            array( $this, 'render_color_field' ),
            'dify-chat-bot',
            'dify_chat_viewer_section',
            array( 'name' => 'dify_chat_viewer_header_color' )
        );

        register_setting( 'dify_chat_bot_settings', 'dify_chat_viewer_bg_color', array(
            'sanitize_callback' => 'sanitize_hex_color',
            'default'           => '#ffffff',
        ) );
        add_settings_field(
            'dify_chat_viewer_bg_color',
            '背景色',
            array( $this, 'render_color_field' ),
            'dify-chat-bot',
            'dify_chat_viewer_section',
            array( 'name' => 'dify_chat_viewer_bg_color' )
        );
    }

    // --- セクション説明 ---

    public function render_api_section() {
        echo '<p>Dify APIへの接続情報を設定してください。</p>';
    }

    public function render_btn_section() {
        echo '<p>チャットを開くフローティングボタンの外観と位置を設定してください。</p>';
    }

    public function render_viewer_section() {
        echo '<p>チャットビュワー（会話ウィンドウ）の外観と位置を設定してください。</p>';
    }

    // --- フィールドレンダリング ---

    /**
     * テキスト入力フィールド
     */
    public function render_text_field( $args ) {
        $value = get_option( $args['name'], '' );
        printf(
            '<input type="text" name="%s" value="%s" class="regular-text" placeholder="%s" />',
            esc_attr( $args['name'] ),
            esc_attr( $value ),
            esc_attr( $args['placeholder'] ?? '' )
        );
        if ( ! empty( $args['description'] ) ) {
            printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
        }
    }

    /**
     * パスワード入力フィールド（APIキー用）
     */
    public function render_password_field( $args ) {
        $value = get_option( $args['name'], '' );
        printf(
            '<input type="password" name="%s" value="%s" class="regular-text" placeholder="%s" autocomplete="off" />',
            esc_attr( $args['name'] ),
            esc_attr( $value ),
            esc_attr( $args['placeholder'] ?? '' )
        );
        if ( ! empty( $args['description'] ) ) {
            printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
        }
    }

    /**
     * 数値入力フィールド
     */
    public function render_number_field( $args ) {
        $value = get_option( $args['name'], 0 );
        printf(
            '<input type="number" name="%s" value="%d" min="%d" max="%d" class="small-text" />',
            esc_attr( $args['name'] ),
            intval( $value ),
            intval( $args['min'] ?? 0 ),
            intval( $args['max'] ?? 1000 )
        );
    }

    /**
     * カラーピッカーフィールド
     */
    public function render_color_field( $args ) {
        $value = get_option( $args['name'], '#0073aa' );
        printf(
            '<input type="text" name="%s" value="%s" class="dify-color-picker" />',
            esc_attr( $args['name'] ),
            esc_attr( $value )
        );
    }

    /**
     * 位置選択ドロップダウン
     */
    public function render_position_field( $args ) {
        $value   = get_option( $args['name'], 'bottom-right' );
        $options = array(
            'bottom-right' => '右下',
            'bottom-left'  => '左下',
            'top-right'    => '右上',
            'top-left'     => '左上',
        );

        echo '<select name="' . esc_attr( $args['name'] ) . '">';
        foreach ( $options as $key => $label ) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr( $key ),
                selected( $value, $key, false ),
                esc_html( $label )
            );
        }
        echo '</select>';
    }

    /**
     * 位置値のサニタイズ
     *
     * @param string $value 入力値
     * @return string 許可リストに含まれる値、またはデフォルト値
     */
    public function sanitize_position( $value ) {
        $allowed = array( 'bottom-right', 'bottom-left', 'top-right', 'top-left' );
        return in_array( $value, $allowed, true ) ? $value : 'bottom-right';
    }

    /**
     * 設定ページのHTML出力
     */
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>Dify Chat Bot 設定</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'dify_chat_bot_settings' );
                do_settings_sections( 'dify-chat-bot' );
                submit_button( '設定を保存' );
                ?>
            </form>
        </div>
        <?php
    }
}
