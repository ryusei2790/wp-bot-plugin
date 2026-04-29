<?php
/**
 * Dify Chat Bot APIプロキシクラス
 *
 * このファイルの責務：
 * - WP REST APIエンドポイントの登録（/dify-chat-bot/v1/send）
 * - フロントエンドからのリクエストバリデーション
 * - Dify Chat APIへのプロキシ通信（wp_remote_post）
 * - レスポンスの整形と返却
 *
 * 設計意図：
 * APIキーをサーバー側のみで保持し、フロントエンドに露出させないためのプロキシ構造。
 * Dify通信部分をsend_to_difyメソッドに分離し、将来のプロバイダー追加に備える。
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Dify_Chat_Bot_Api {

    /**
     * コンストラクタ：REST APIルートの登録フックを設定
     */
    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    /**
     * REST APIルートの登録
     * POST /wp-json/dify-chat-bot/v1/send
     */
    public function register_routes() {
        register_rest_route( 'dify-chat-bot/v1', '/send', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_send' ),
            'permission_callback' => '__return_true',
            'args'                => array(
                'message' => array(
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'conversation_id' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'default'           => '',
                ),
            ),
        ) );
    }

    /**
     * メッセージ送信リクエストのハンドラ
     * バリデーション → Dify API通信 → レスポンス返却
     *
     * @param WP_REST_Request $request リクエストオブジェクト
     * @return WP_REST_Response|WP_Error レスポンス
     */
    public function handle_send( $request ) {
        $message         = $request->get_param( 'message' );
        $conversation_id = $request->get_param( 'conversation_id' );

        // メッセージが空の場合はエラー
        if ( empty( trim( $message ) ) ) {
            return new WP_Error(
                'empty_message',
                'メッセージを入力してください。',
                array( 'status' => 400 )
            );
        }

        // API設定の取得と検証
        $api_url = get_option( 'dify_chat_api_url', '' );
        $api_key = get_option( 'dify_chat_api_key', '' );

        if ( empty( $api_url ) || empty( $api_key ) ) {
            return new WP_Error(
                'api_not_configured',
                'APIが設定されていません。管理画面で設定してください。',
                array( 'status' => 500 )
            );
        }

        // Dify APIに送信
        $result = $this->send_to_dify( $api_url, $api_key, $message, $conversation_id );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return new WP_REST_Response( $result, 200 );
    }

    /**
     * Dify Chat APIへメッセージを送信
     *
     * Difyの /chat-messages エンドポイントにPOSTリクエストを送信し、
     * AIの応答とconversation_idを取得する。
     *
     * @param string $api_url          DifyのAPIベースURL
     * @param string $api_key          DifyのAPIキー
     * @param string $message          ユーザーのメッセージ
     * @param string $conversation_id  会話ID（初回は空文字）
     * @return array|WP_Error 成功時はanswer・conversation_idを含む配列、失敗時はWP_Error
     */
    private function send_to_dify( $api_url, $api_key, $message, $conversation_id ) {
        // URLの末尾スラッシュを正規化
        $api_url = rtrim( $api_url, '/' );
        $endpoint = $api_url . '/chat-messages';

        // ユーザー識別子（セッションベース）
        $user_id = 'wp-user-' . wp_generate_uuid4();

        $body = array(
            'inputs'          => new stdClass(),
            'query'           => $message,
            'response_mode'   => 'blocking',
            'user'            => $user_id,
        );

        // 既存の会話がある場合はconversation_idを付与
        if ( ! empty( $conversation_id ) ) {
            $body['conversation_id'] = $conversation_id;
        }

        $response = wp_remote_post( $endpoint, array(
            'timeout' => 60,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $body ),
        ) );

        // HTTP通信エラー
        if ( is_wp_error( $response ) ) {
            return new WP_Error(
                'dify_connection_error',
                'Dify APIへの接続に失敗しました: ' . $response->get_error_message(),
                array( 'status' => 502 )
            );
        }

        $status_code   = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        $data          = json_decode( $response_body, true );

        // Dify APIからのエラーレスポンス
        if ( $status_code !== 200 ) {
            $error_msg = isset( $data['message'] ) ? $data['message'] : 'Dify APIエラー';
            return new WP_Error(
                'dify_api_error',
                $error_msg,
                array( 'status' => $status_code )
            );
        }

        // 正常レスポンスの整形
        return array(
            'answer'          => isset( $data['answer'] ) ? $data['answer'] : '',
            'conversation_id' => isset( $data['conversation_id'] ) ? $data['conversation_id'] : '',
        );
    }
}
