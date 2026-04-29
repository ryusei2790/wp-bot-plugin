/**
 * Dify Chat Bot フロントエンドウィジェット
 *
 * このファイルの責務：
 * - チャットボタン・ビュワーのDOM要素を動的に生成
 * - ボタンクリックでビュワーの開閉を制御
 * - ユーザーメッセージをWP REST APIに送信
 * - AIの応答を受信してチャットに表示
 * - conversation_idをsessionStorageで管理し、同一タブ内で会話を継続
 *
 * 依存：
 * - difyChatBot.restUrl : WP REST APIエンドポイント（PHP側からwp_localize_scriptで注入）
 * - difyChatBot.nonce   : WP REST API認証用nonce
 */

(function () {
    'use strict';

    // PHP側から注入された設定の存在チェック
    if (typeof difyChatBot === 'undefined') {
        return;
    }

    /** sessionStorageキー */
    var STORAGE_KEY = 'dify_chat_conversation_id';

    /** 送信中フラグ */
    var isSending = false;

    /** DOM参照をまとめて保持 */
    var els = {};

    // =============================================
    // DOM生成
    // =============================================

    /**
     * チャットウィジェット全体のDOM構造を生成しbodyに追加する。
     * SVGアイコンはインラインで埋め込み、外部依存をゼロにする。
     */
    function createWidget() {
        // --- チャットボタン ---
        var btn = document.createElement('button');
        btn.className = 'dify-chat-btn';
        btn.setAttribute('aria-label', 'チャットを開く');
        btn.innerHTML =
            '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">' +
            '<path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/>' +
            '</svg>';

        // --- チャットビュワー ---
        var viewer = document.createElement('div');
        viewer.className = 'dify-chat-viewer dify-chat-viewer--hidden';

        // ヘッダー
        var header = document.createElement('div');
        header.className = 'dify-chat-header';
        header.innerHTML =
            '<h3 class="dify-chat-header__title">AI チャット</h3>' +
            '<button class="dify-chat-header__close" aria-label="チャットを閉じる">' +
            '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">' +
            '<path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>' +
            '</svg></button>';

        // メッセージエリア
        var messages = document.createElement('div');
        messages.className = 'dify-chat-messages';
        messages.innerHTML =
            '<div class="dify-chat-welcome">メッセージを入力して会話を始めましょう。</div>';

        // 入力エリア
        var inputArea = document.createElement('div');
        inputArea.className = 'dify-chat-input';

        var textarea = document.createElement('textarea');
        textarea.className = 'dify-chat-input__text';
        textarea.placeholder = 'メッセージを入力...';
        textarea.rows = 1;

        var sendBtn = document.createElement('button');
        sendBtn.className = 'dify-chat-input__send';
        sendBtn.setAttribute('aria-label', 'メッセージを送信');
        sendBtn.innerHTML =
            '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">' +
            '<path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>' +
            '</svg>';

        inputArea.appendChild(textarea);
        inputArea.appendChild(sendBtn);

        viewer.appendChild(header);
        viewer.appendChild(messages);
        viewer.appendChild(inputArea);

        document.body.appendChild(btn);
        document.body.appendChild(viewer);

        // DOM参照を保存
        els.btn = btn;
        els.viewer = viewer;
        els.closeBtn = header.querySelector('.dify-chat-header__close');
        els.messages = messages;
        els.textarea = textarea;
        els.sendBtn = sendBtn;
    }

    // =============================================
    // イベントバインド
    // =============================================

    /**
     * 各UI要素にイベントリスナーを設定
     */
    function bindEvents() {
        // チャットボタン → ビュワーを開く
        els.btn.addEventListener('click', openViewer);

        // 閉じるボタン → ビュワーを閉じる
        els.closeBtn.addEventListener('click', closeViewer);

        // 送信ボタン
        els.sendBtn.addEventListener('click', handleSend);

        // Enter で送信（Shift+Enter は改行）
        els.textarea.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                handleSend();
            }
        });

        // テキストエリアの自動リサイズ
        els.textarea.addEventListener('input', function () {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 80) + 'px';
        });
    }

    // =============================================
    // 開閉制御
    // =============================================

    function openViewer() {
        els.viewer.classList.remove('dify-chat-viewer--hidden');
        els.btn.classList.add('dify-chat-btn--hidden');
        els.textarea.focus();
    }

    function closeViewer() {
        els.viewer.classList.add('dify-chat-viewer--hidden');
        els.btn.classList.remove('dify-chat-btn--hidden');
    }

    // =============================================
    // メッセージ送受信
    // =============================================

    /**
     * 送信処理のメインハンドラ
     * 1. 入力テキストを取得・検証
     * 2. ユーザーメッセージをUIに表示
     * 3. ローディング表示
     * 4. WP REST APIにPOST
     * 5. AIの応答を表示
     */
    function handleSend() {
        if (isSending) return;

        var message = els.textarea.value.trim();
        if (!message) return;

        // 入力クリア
        els.textarea.value = '';
        els.textarea.style.height = 'auto';

        // ウェルカムメッセージを消す
        var welcome = els.messages.querySelector('.dify-chat-welcome');
        if (welcome) {
            welcome.remove();
        }

        // ユーザーメッセージ表示
        appendMessage(message, 'user');

        // ローディング表示
        var loadingEl = appendLoading();

        // 送信状態にする
        isSending = true;
        els.sendBtn.disabled = true;

        // API送信
        var conversationId = sessionStorage.getItem(STORAGE_KEY) || '';

        fetch(difyChatBot.restUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': difyChatBot.nonce,
            },
            body: JSON.stringify({
                message: message,
                conversation_id: conversationId,
            }),
        })
            .then(function (response) {
                return response.json().then(function (data) {
                    return { ok: response.ok, data: data };
                });
            })
            .then(function (result) {
                // ローディングを除去
                if (loadingEl && loadingEl.parentNode) {
                    loadingEl.remove();
                }

                if (result.ok) {
                    // 成功：AI応答を表示
                    appendMessage(result.data.answer, 'bot');

                    // conversation_idを保存
                    if (result.data.conversation_id) {
                        sessionStorage.setItem(STORAGE_KEY, result.data.conversation_id);
                    }
                } else {
                    // APIエラー
                    var errorMsg = result.data.message || 'エラーが発生しました。';
                    appendMessage(errorMsg, 'error');
                }
            })
            .catch(function () {
                // ネットワークエラー
                if (loadingEl && loadingEl.parentNode) {
                    loadingEl.remove();
                }
                appendMessage('通信エラーが発生しました。しばらくしてからお試しください。', 'error');
            })
            .finally(function () {
                isSending = false;
                els.sendBtn.disabled = false;
                els.textarea.focus();
            });
    }

    /**
     * メッセージバブルをチャットに追加
     *
     * @param {string} text メッセージテキスト
     * @param {string} type メッセージ種別（'user' | 'bot' | 'error'）
     */
    function appendMessage(text, type) {
        var div = document.createElement('div');
        div.className = 'dify-chat-message dify-chat-message--' + type;
        div.textContent = text;
        els.messages.appendChild(div);
        scrollToBottom();
    }

    /**
     * ローディングインジケーターを追加
     *
     * @return {HTMLElement} ローディング要素（後で除去するため返す）
     */
    function appendLoading() {
        var div = document.createElement('div');
        div.className = 'dify-chat-message dify-chat-message--loading';
        div.innerHTML =
            '<div class="dify-chat-loading-dots">' +
            '<span></span><span></span><span></span>' +
            '</div>';
        els.messages.appendChild(div);
        scrollToBottom();
        return div;
    }

    /**
     * メッセージエリアを最下部にスクロール
     */
    function scrollToBottom() {
        els.messages.scrollTop = els.messages.scrollHeight;
    }

    // =============================================
    // 初期化
    // =============================================

    /**
     * DOMContentLoaded後にウィジェットを初期化
     */
    function init() {
        createWidget();
        bindEvents();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
