<?php

use function MailOptin\Core\moVar;

if ( ! class_exists('\MoBFnote')) {

    class MoBFnote
    {
        public function __construct()
        {
            add_action('mailoptin_admin_notices', function () {
                add_action('admin_notices', array($this, 'admin_notice'));
            });
            add_action('network_admin_notices', array($this, 'admin_notice'));

            add_action('admin_init', array($this, 'dismiss_admin_notice'));
        }

        public function dismiss_admin_notice()
        {
            if ( ! isset($_GET['mobfnote-adaction']) || $_GET['mobfnote-adaction'] != 'mobfnote_dismiss_adnotice') {
                return;
            }

            $url = admin_url();
            update_option('mobfnote_dismiss_adnotice', 'true');

            wp_redirect($url);
            exit;
        }

        public function admin_notice()
        {
            global $pagenow;

            if ($pagenow != 'index.php' && strpos(moVar($_GET, 'page'), 'mailoptin-') === false) return;

            if (defined('MAILOPTIN_DETACH_LIBSODIUM')) return;

            if ( ! current_user_can('administrator')) return;

            $start = strtotime('november 24th, 2021');
            $end   = strtotime('december 1st, 2021');
            $now   = time();

            if ($now < $start || $now > $end) return;

            if (get_option('mobfnote_dismiss_adnotice', 'false') == 'true') {
                return;
            }

            $dismiss_url = esc_url_raw(
                add_query_arg(
                    array(
                        'mobfnote-adaction' => 'mobfnote_dismiss_adnotice'
                    ),
                    admin_url()
                )
            );
            $this->notice_css();

            $bf_url = 'https://mailoptin.io/pricing/?utm_source=wp-admin&utm_medium=admin-notice&utm_id=bf2021'

            ?>
            <div class="mobfnote-admin-notice notice notice-success">
                <div class="mobfnote-notice-first-half">
                    <p>
                        <?php
                        printf(
                            __('%1$sHuge Black Friday Sale%2$s: Get 40%% off your MailOptin plugin upgrade today with the coupon %3$sBFCM2021%4$s', 'peters-login-redirect'),
                            '<span class="mobfnote-stylize"><strong>', '</strong></span>', '<code>', '</code>');
                        ?>
                    </p>
                    <p style="text-decoration: underline;font-size: 12px;">Hurry as the deal is expiring soon.</p>

                </div>
                <div class="mobfnote-notice-other-half">
                    <a target="_blank" class="button button-primary button-hero" id="mobfnote-install-mailoptin-plugin" href="<?php echo $bf_url; ?>">
                        <?php _e('Save 40% Now!', 'peters-login-redirect'); ?>
                    </a>
                    <div class="mobfnote-notice-learn-more">
                        <a target="_blank" href="<?php echo $bf_url; ?>">Learn more</a>
                    </div>
                </div>
                <a href="<?php echo $dismiss_url; ?>">
                    <button type="button" class="notice-dismiss">
                        <span class="screen-reader-text"><?php _e('Dismiss this notice', 'peters-login-redirect'); ?>.</span>
                    </button>
                </a>
            </div>
            <?php
        }

        public function notice_css()
        {
            ?>
            <style type="text/css">
                .mobfnote-admin-notice {
                    background: #fff;
                    color: #000;
                    border-left-color: #46b450;
                    position: relative;
                }

                .mobfnote-admin-notice .notice-dismiss:before {
                    color: #72777c;
                }

                .mobfnote-admin-notice .mobfnote-stylize {
                    line-height: 2;
                }

                .mobfnote-admin-notice .button-primary {
                    background: #006799;
                    text-shadow: none;
                    border: 0;
                    box-shadow: none;
                }

                .mobfnote-notice-first-half {
                    width: 66%;
                    display: inline-block;
                    margin: 10px 0 20px;
                }

                .mobfnote-notice-other-half {
                    width: 33%;
                    display: inline-block;
                    padding: 20px 0;
                    position: absolute;
                    text-align: center;
                }

                .mobfnote-notice-first-half p {
                    font-size: 14px;
                }

                .mobfnote-notice-learn-more a {
                    margin: 10px;
                }

                .mobfnote-notice-learn-more {
                    margin-top: 10px;
                }
            </style>
            <?php
        }

        public static function instance()
        {
            static $instance = null;

            if (is_null($instance)) {
                $instance = new self();
            }

            return $instance;
        }
    }
}