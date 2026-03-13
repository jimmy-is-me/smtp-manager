<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Gmail_OC_Settings {

    private static $instance;

    public static function get_instance() {
        if ( ! isset( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action( 'admin_menu',            array( $this, 'add_menu' ) );
        add_action( 'admin_init',            array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'admin_post_gmail_oc_save_settings', array( $this, 'save_settings' ) );
        add_action( 'admin_post_gmail_oc_send_test',     array( $this, 'send_test_email' ) );
    }

    public function add_menu() {
        add_menu_page(
            __( 'Gmail One-Click SMTP', 'gmail-oneclick' ),
            __( 'Gmail SMTP', 'gmail-oneclick' ),
            'manage_options',
            'gmail-oneclick',
            array( $this, 'render_page' ),
            'dashicons-email-alt',
            80
        );
    }

    public function register_settings() {
        register_setting( 'gmail_oc_settings_group', 'gmail_oc_settings', array(
            'sanitize_callback' => array( $this, 'sanitize_settings' ),
        ) );
    }

    public function sanitize_settings( $input ) {
        return array(
            'client_id'      => sanitize_text_field( $input['client_id']      ?? '' ),
            'client_secret'  => sanitize_text_field( $input['client_secret']  ?? '' ),
            'from_email'     => sanitize_email(      $input['from_email']      ?? '' ),
            'from_name'      => sanitize_text_field( $input['from_name']       ?? '' ),
        );
    }

    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'gmail-oneclick' ) === false ) return;
        wp_enqueue_style(  'gmail-oc-admin', GMAIL_OC_URL . 'assets/css/admin.css', array(), GMAIL_OC_VERSION );
        wp_enqueue_script( 'gmail-oc-admin', GMAIL_OC_URL . 'assets/js/admin.js',  array( 'jquery' ), GMAIL_OC_VERSION, true );
    }

    public function save_settings() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Permission denied.' );
        check_admin_referer( 'gmail_oc_save_settings' );

        $settings = array(
            'client_id'     => sanitize_text_field( $_POST['client_id']     ?? '' ),
            'client_secret' => sanitize_text_field( $_POST['client_secret'] ?? '' ),
            'from_email'    => sanitize_email(      $_POST['from_email']     ?? '' ),
            'from_name'     => sanitize_text_field( $_POST['from_name']      ?? '' ),
        );
        update_option( 'gmail_oc_settings', $settings );
        set_transient( 'gmail_oc_success', '✅ 設定已儲存。', 30 );
        wp_redirect( admin_url( 'admin.php?page=gmail-oneclick' ) );
        exit;
    }

    public function send_test_email() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Permission denied.' );
        check_admin_referer( 'gmail_oc_send_test' );

        $to = sanitize_email( $_POST['test_email'] ?? get_option( 'admin_email' ) );

        $result = wp_mail(
            $to,
            '✅ Gmail One-Click SMTP 測試信',
            "這是一封測試信，確認 Gmail API 寄信功能正常運作。\n\n時間：" . current_time( 'mysql' )
        );

        if ( $result ) {
            set_transient( 'gmail_oc_success', "✅ 測試信已成功寄出到 {$to}！", 30 );
        } else {
            set_transient( 'gmail_oc_error', '❌ 寄信失敗，請確認授權與設定是否正確。', 30 );
        }

        wp_redirect( admin_url( 'admin.php?page=gmail-oneclick' ) );
        exit;
    }

    public function render_page() {
        $options      = get_option( 'gmail_oc_settings', array() );
        $is_connected = Gmail_OC_Auth::is_connected();
        $auth_url     = Gmail_OC_Auth::get_auth_url();
        $conn_email   = Gmail_OC_Auth::get_connected_email();
        $redirect_uri = Gmail_OC_Auth::get_redirect_uri();
        $success      = get_transient( 'gmail_oc_success' );
        $error        = get_transient( 'gmail_oc_error' );
        delete_transient( 'gmail_oc_success' );
        delete_transient( 'gmail_oc_error' );
        ?>
        <div class="wrap gmail-oc-wrap">
            <h1>
                <span class="dashicons dashicons-email-alt" style="font-size:30px;vertical-align:middle;margin-right:8px;color:#EA4335;"></span>
                <?php _e( 'Gmail One-Click SMTP', 'gmail-oneclick' ); ?>
            </h1>

            <?php if ( $success ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $success ); ?></p></div>
            <?php endif; ?>
            <?php if ( $error ) : ?>
                <div class="notice notice-error is-dismissible"><p><?php echo esc_html( $error ); ?></p></div>
            <?php endif; ?>

            <!-- 連線狀態卡片 -->
            <div class="gmail-oc-card">
                <h2><?php _e( '連線狀態', 'gmail-oneclick' ); ?></h2>
                <?php if ( $is_connected ) : ?>
                    <div class="gmail-oc-status connected">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <?php printf( __( '已連線：<strong>%s</strong>', 'gmail-oneclick' ), esc_html( $conn_email ?: __( 'Gmail 帳號', 'gmail-oneclick' ) ) ); ?>
                    </div>
                    <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=gmail_oc_disconnect' ), 'gmail_oc_disconnect' ) ); ?>"
                       class="button button-secondary gmail-oc-disconnect"
                       onclick="return confirm('確定要中斷 Gmail 授權？')">
                        <?php _e( '🔌 中斷授權', 'gmail-oneclick' ); ?>
                    </a>
                <?php else : ?>
                    <div class="gmail-oc-status disconnected">
                        <span class="dashicons dashicons-dismiss"></span>
                        <?php _e( '尚未連線', 'gmail-oneclick' ); ?>
                    </div>
                    <?php if ( ! empty( $options['client_id'] ) && ! empty( $options['client_secret'] ) ) : ?>
                        <a href="<?php echo esc_url( $auth_url ); ?>" class="button button-primary gmail-oc-connect-btn">
                            <img src="https://www.google.com/favicon.ico" width="16" height="16" style="vertical-align:middle;margin-right:6px;" />
                            <?php _e( '使用 Google 帳號授權', 'gmail-oneclick' ); ?>
                        </a>
                    <?php else : ?>
                        <p class="description" style="color:#d63638;">
                            <?php _e( '⚠️ 請先填寫下方的 Client ID 與 Client Secret，再進行授權。', 'gmail-oneclick' ); ?>
                        </p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Google API 設定 -->
            <div class="gmail-oc-card">
                <h2><?php _e( 'Google API 設定', 'gmail-oneclick' ); ?></h2>
                <p class="description">
                    <?php printf(
                        __( '請至 <a href="%s" target="_blank">Google Cloud Console</a> 建立 OAuth 2.0 憑證，並將以下 Redirect URI 加入允許清單：', 'gmail-oneclick' ),
                        'https://console.cloud.google.com/apis/credentials'
                    ); ?>
                </p>
                <code class="gmail-oc-redirect-uri"><?php echo esc_url( $redirect_uri ); ?></code>

                <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="gmail_oc_save_settings" />
                    <?php wp_nonce_field( 'gmail_oc_save_settings' ); ?>

                    <table class="form-table">
                        <tr>
                            <th><label for="client_id"><?php _e( 'Client ID', 'gmail-oneclick' ); ?></label></th>
                            <td>
                                <input type="text" id="client_id" name="client_id"
                                       value="<?php echo esc_attr( $options['client_id'] ?? '' ); ?>"
                                       class="regular-text" autocomplete="off" />
                            </td>
                        </tr>
                        <tr>
                            <th><label for="client_secret"><?php _e( 'Client Secret', 'gmail-oneclick' ); ?></label></th>
                            <td>
                                <input type="password" id="client_secret" name="client_secret"
                                       value="<?php echo esc_attr( $options['client_secret'] ?? '' ); ?>"
                                       class="regular-text" autocomplete="off" />
                                <button type="button" class="button gmail-oc-toggle-secret"><?php _e( '顯示', 'gmail-oneclick' ); ?></button>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="from_name"><?php _e( '寄件人名稱', 'gmail-oneclick' ); ?></label></th>
                            <td>
                                <input type="text" id="from_name" name="from_name"
                                       value="<?php echo esc_attr( $options['from_name'] ?? get_bloginfo( 'name' ) ); ?>"
                                       class="regular-text" />
                            </td>
                        </tr>
                        <tr>
                            <th><label for="from_email"><?php _e( '寄件人 Email', 'gmail-oneclick' ); ?></label></th>
                            <td>
                                <input type="email" id="from_email" name="from_email"
                                       value="<?php echo esc_attr( $options['from_email'] ?? get_option( 'admin_email' ) ); ?>"
                                       class="regular-text" />
                                <p class="description"><?php _e( '必須與授權的 Gmail 帳號相同。', 'gmail-oneclick' ); ?></p>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="submit" class="button button-primary"><?php _e( '💾 儲存設定', 'gmail-oneclick' ); ?></button>
                    </p>
                </form>
            </div>

            <!-- 測試寄信 -->
            <?php if ( $is_connected ) : ?>
            <div class="gmail-oc-card">
                <h2><?php _e( '📧 測試寄信', 'gmail-oneclick' ); ?></h2>
                <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="gmail_oc_send_test" />
                    <?php wp_nonce_field( 'gmail_oc_send_test' ); ?>
                    <table class="form-table">
                        <tr>
                            <th><label for="test_email"><?php _e( '收件人 Email', 'gmail-oneclick' ); ?></label></th>
                            <td>
                                <input type="email" id="test_email" name="test_email"
                                       value="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"
                                       class="regular-text" />
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" class="button button-primary"><?php _e( '🚀 寄出測試信', 'gmail-oneclick' ); ?></button>
                    </p>
                </form>
            </div>
            <?php endif; ?>

            <!-- 設定教學 -->
            <div class="gmail-oc-card gmail-oc-guide">
                <h2><?php _e( '📖 設定步驟', 'gmail-oneclick' ); ?></h2>
                <ol>
                    <li><?php printf( __( '前往 <a href="%s" target="_blank">Google Cloud Console</a> 建立或選擇一個專案。', 'gmail-oneclick' ), 'https://console.cloud.google.com/' ); ?></li>
                    <li><?php _e( '啟用 <strong>Gmail API</strong>。', 'gmail-oneclick' ); ?></li>
                    <li><?php _e( '前往「憑證」→「建立憑證」→「OAuth 用戶端 ID」，應用程式類型選「網頁應用程式」。', 'gmail-oneclick' ); ?></li>
                    <li><?php printf( __( '在「已授權的重新導向 URI」加入：<code>%s</code>', 'gmail-oneclick' ), esc_url( $redirect_uri ) ); ?></li>
                    <li><?php _e( '複製 Client ID 與 Client Secret 填入上方表單並儲存。', 'gmail-oneclick' ); ?></li>
                    <li><?php _e( '點擊「使用 Google 帳號授權」完成一鍵連線。', 'gmail-oneclick' ); ?></li>
                </ol>
            </div>
        </div>
        <?php
    }
}
