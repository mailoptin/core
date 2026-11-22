<?php
// Copied from mailoptin/wp-email-senders by bin/sync-email-senders.php. Change it there.

// PHPMailer's OAuth interface (PHPMailer 6.6+), which WordPress leaves out of its copy. Same name
// and signature as PHPMailer's own, so either works with setOAuth(). Loaded only when missing.
namespace PHPMailer\PHPMailer;

if ( ! interface_exists(__NAMESPACE__ . '\OAuthTokenProvider', false)) {
    interface OAuthTokenProvider
    {
        /**
         * The XOAUTH2 string, base64 encoded: "user=" . $email . "\001auth=Bearer " . $token . "\001\001".
         *
         * @return string
         */
        public function getOauth64();
    }
}
