<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Gmail_OC_Mailer {

    private static $instance;

    public static function get_instance() {
        if ( ! isset( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // 接管 PHPMailer，改用 Gmail API 發送
        add_action( 'phpmailer_init', array( $this, 'override_phpmailer' ), 999 );
        // 強制套用 From Name / From Email
        add_filter( 'wp_mail_from',      array( $this, 'set_from_email' ) );
        add_filter( 'wp_mail_from_name', array( $this, 'set_from_name' ) );
    }

    public function set_from_email( $email ) {
        $options = get_option( 'gmail_oc_settings', array() );
        return ! empty( $options['from_email'] ) ? $options['from_email'] : $email;
    }

    public function set_from_name( $name ) {
        $options = get_option( 'gmail_oc_settings', array() );
        return ! empty( $options['from_name'] ) ? $options['from_name'] : $name;
    }

    /**
     * 覆寫 PHPMailer，改用 Gmail API（HTTP）發送
     */
    public function override_phpmailer( $phpmailer ) {
        $phpmailer->Mailer = 'gmail_api'; // 自訂識別

        // 掛入寄信前攔截
        add_filter( 'pre_wp_mail', array( $this, 'send_via_gmail_api' ), 10, 2 );
    }

    /**
     * 使用 Gmail API 寄信
     * 
     * @param null|bool $return
     * @param array     $atts  wp_mail 的參數
     * @return bool
     */
    public function send_via_gmail_api( $return, $atts ) {
        // 避免重複觸發
        remove_filter( 'pre_wp_mail', array( $this, 'send_via_gmail_api' ), 10 );

        $access_token = Gmail_OC_Auth::maybe_refresh_token();

        if ( ! $access_token ) {
            error_log( 'Gmail One-Click: 無法取得有效的 access token。' );
            return false;
        }

        $options    = get_option( 'gmail_oc_settings', array() );
        $from_email = $options['from_email'] ?? get_option( 'admin_email' );
        $from_name  = $options['from_name']  ?? get_bloginfo( 'name' );

        $to      = is_array( $atts['to'] ) ? implode( ', ', $atts['to'] ) : $atts['to'];
        $subject = $atts['subject'];
        $message = $atts['message'];
        $headers = $atts['headers'] ?? array();

        // 組合 MIME 郵件
        $raw_message = $this->build_mime_message( array(
            'from'    => "{$from_name} <{$from_email}>",
            'to'      => $to,
            'subject' => $subject,
            'body'    => $message,
            'headers' => $headers,
        ) );

        // 呼叫 Gmail API
        $response = wp_remote_post(
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/send',
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type'  => 'application/json',
                ),
                'body' => wp_json_encode( array(
                    'raw' => $raw_message,
                ) ),
                'timeout' => 15,
            )
        );

        if ( is_wp_error( $response ) ) {
            error_log( 'Gmail One-Click API Error: ' . $response->get_error_message() );
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );

        if ( $code !== 200 ) {
            $body = wp_remote_retrieve_body( $response );
            error_log( "Gmail One-Click API HTTP {$code}: {$body}" );
            return false;
        }

        return true; // 回傳 true 表示已攔截並成功寄出，跳過 PHPMailer
    }

    /**
     * 組合 Base64 URL 編碼的 MIME 郵件
     */
    private function build_mime_message( $args ) {
        $from    = $args['from'];
        $to      = $args['to'];
        $subject = $args['subject'];
        $body    = $args['body'];
        $headers = $args['headers'];

        $content_type = 'text/plain; charset=UTF-8';
        $extra_headers = '';

        if ( ! empty( $headers ) ) {
            $headers_str = is_array( $headers ) ? implode( "\r\n", $headers ) : $headers;
            if ( strpos( $headers_str, 'Content-Type: text/html' ) !== false ) {
                $content_type = 'text/html; charset=UTF-8';
            }
            // 加入額外 headers（CC、BCC 等）
            foreach ( (array) $headers as $header ) {
                if ( preg_match( '/^(CC|BCC|Reply-To):/i', $header ) ) {
                    $extra_headers .= $header . "\r\n";
                }
            }
        }

        $mime = "From: {$from}\r\n"
              . "To: {$to}\r\n"
              . "Subject: =?UTF-8?B?" . base64_encode( $subject ) . "?=\r\n"
              . "MIME-Version: 1.0\r\n"
              . "Content-Type: {$content_type}\r\n"
              . $extra_headers
              . "\r\n"
              . $body;

        // Gmail API 使用 Base64 URL-safe 編碼
        return rtrim( strtr( base64_encode( $mime ), '+/', '-_' ), '=' );
    }
}
