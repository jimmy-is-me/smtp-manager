<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Gmail_OC_Auth {

    private static $instance;

    // Google OAuth 端點
    const AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
    const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    const SCOPE     = 'https://mail.google.com/';

    public static function get_instance() {
        if ( ! isset( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action( 'admin_init', array( $this, 'handle_oauth_callback' ) );
        add_action( 'admin_post_gmail_oc_disconnect', array( $this, 'disconnect' ) );
    }

    /**
     * 取得 Google OAuth 授權 URL
     */
    public static function get_auth_url() {
        $options     = get_option( 'gmail_oc_settings', array() );
        $client_id   = isset( $options['client_id'] ) ? $options['client_id'] : '';
        $redirect_uri = self::get_redirect_uri();

        if ( empty( $client_id ) ) return '';

        $params = array(
            'client_id'             => $client_id,
            'redirect_uri'          => $redirect_uri,
            'response_type'         => 'code',
            'scope'                 => self::SCOPE,
            'access_type'           => 'offline',
            'prompt'                => 'consent',
            'state'                 => wp_create_nonce( 'gmail_oc_oauth' ),
        );

        return self::AUTH_URL . '?' . http_build_query( $params );
    }

    /**
     * 處理 Google 回傳的 OAuth callback
     */
    public function handle_oauth_callback() {
        if (
            ! isset( $_GET['page'] )  ||
            $_GET['page'] !== 'gmail-oneclick' ||
            ! isset( $_GET['code'] )
        ) {
            return;
        }

        if ( ! wp_verify_nonce( $_GET['state'], 'gmail_oc_oauth' ) ) {
            wp_die( 'Security check failed.' );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Permission denied.' );
        }

        $tokens = $this->exchange_code_for_tokens( sanitize_text_field( $_GET['code'] ) );

        if ( is_wp_error( $tokens ) ) {
            set_transient( 'gmail_oc_error', $tokens->get_error_message(), 30 );
        } else {
            update_option( 'gmail_oc_tokens', $tokens );
            set_transient( 'gmail_oc_success', '✅ Gmail 授權成功！現在可以使用 Gmail API 寄信。', 30 );
        }

        wp_redirect( admin_url( 'admin.php?page=gmail-oneclick' ) );
        exit;
    }

    /**
     * 用授權碼換取 access_token + refresh_token
     */
    private function exchange_code_for_tokens( $code ) {
        $options      = get_option( 'gmail_oc_settings', array() );
        $client_id    = isset( $options['client_id'] )     ? $options['client_id']     : '';
        $client_secret= isset( $options['client_secret'] ) ? $options['client_secret'] : '';

        $response = wp_remote_post( self::TOKEN_URL, array(
            'body' => array(
                'code'          => $code,
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'redirect_uri'  => self::get_redirect_uri(),
                'grant_type'    => 'authorization_code',
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $body['error'] ) ) {
            return new WP_Error( 'oauth_error', $body['error_description'] ?? $body['error'] );
        }

        $body['obtained_at'] = time();
        return $body;
    }

    /**
     * 自動更新過期的 access_token
     */
    public static function maybe_refresh_token() {
        $tokens        = get_option( 'gmail_oc_tokens', array() );
        $options       = get_option( 'gmail_oc_settings', array() );
        $client_id     = isset( $options['client_id'] )     ? $options['client_id']     : '';
        $client_secret = isset( $options['client_secret'] ) ? $options['client_secret'] : '';

        if ( empty( $tokens['refresh_token'] ) ) return false;

        $expires_at = ( $tokens['obtained_at'] ?? 0 ) + ( $tokens['expires_in'] ?? 3600 );

        // 距離過期還有 5 分鐘以上就不換
        if ( time() < $expires_at - 300 ) {
            return $tokens['access_token'];
        }

        $response = wp_remote_post( self::TOKEN_URL, array(
            'body' => array(
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'refresh_token' => $tokens['refresh_token'],
                'grant_type'    => 'refresh_token',
            ),
        ) );

        if ( is_wp_error( $response ) ) return false;

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $body['access_token'] ) ) {
            $tokens['access_token'] = $body['access_token'];
            $tokens['obtained_at']  = time();
            $tokens['expires_in']   = $body['expires_in'] ?? 3600;
            update_option( 'gmail_oc_tokens', $tokens );
            return $tokens['access_token'];
        }

        return false;
    }

    /**
     * 檢查是否已連線
     */
    public static function is_connected() {
        $tokens = get_option( 'gmail_oc_tokens', array() );
        return ! empty( $tokens['access_token'] ) && ! empty( $tokens['refresh_token'] );
    }

    /**
     * 取得已連線的 Gmail 地址（存在 options 裡）
     */
    public static function get_connected_email() {
        $tokens = get_option( 'gmail_oc_tokens', array() );
        return isset( $tokens['email'] ) ? $tokens['email'] : '';
    }

    /**
     * 中斷授權
     */
    public function disconnect() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Permission denied.' );
        if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'gmail_oc_disconnect' ) ) wp_die( 'Security check failed.' );

        delete_option( 'gmail_oc_tokens' );
        set_transient( 'gmail_oc_success', '已成功中斷 Gmail 授權。', 30 );
        wp_redirect( admin_url( 'admin.php?page=gmail-oneclick' ) );
        exit;
    }

    /**
     * Redirect URI（固定指向 Gmail One-Click 設定頁）
     */
    public static function get_redirect_uri() {
        return admin_url( 'admin.php?page=gmail-oneclick' );
    }

    public static function on_activate() {}
    public static function on_deactivate() {
        delete_option( 'gmail_oc_tokens' );
        delete_option( 'gmail_oc_settings' );
    }
}
