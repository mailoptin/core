<?php

namespace MailOptin\Core\Sending;

/**
 * For working on mailoptin/wp-email-senders: run a clone of the package instead of the copy in
 * src/EmailSenders, with no sync step between an edit and the site.
 *
 *     define('MAILOPTIN_EMAIL_SENDERS_DIR', '/path/to/wp-email-senders');   // in wp-config.php
 *
 * Each MailOptin\Core\EmailSenders\X is then the clone's MailOptin\EmailSenders\X under MailOptin's
 * name (class_alias), so instanceof checks and type hints work across both names. Remove the constant,
 * or set it to false, and the bundled copy is used again.
 *
 * Development only: a site running FuseWP's or another plugin's development clone at the same time
 * would share the package's own class names.
 */
class PackageSource
{
    const CONSTANT = 'MAILOPTIN_EMAIL_SENDERS_DIR';

    const OURS    = 'MailOptin\\Core\\EmailSenders\\';
    const PACKAGE = 'MailOptin\\EmailSenders\\';

    /** @var string|null the clone in use, null for the bundled copy */
    protected static $dir = null;

    /**
     * Call before anything loads a MailOptin\Core\EmailSenders class.
     */
    public static function register()
    {
        if (null !== self::$dir || ! defined(self::CONSTANT) || ! constant(self::CONSTANT)) return;

        $dir = rtrim((string)constant(self::CONSTANT), '/\\');

        if ( ! is_file($dir . '/src/Senders.php')) {
            add_action('admin_notices', function () use ($dir) {
                if ( ! current_user_can('manage_options')) return;
                printf('<div class="notice notice-error"><p>%s</p></div>', esc_html(sprintf(
                    'MAILOPTIN_EMAIL_SENDERS_DIR is set to "%s", which isn\'t a wp-email-senders checkout (no src/Senders.php). MailOptin is using its bundled copy.',
                    $dir
                )));
            });

            return;
        }

        self::$dir = $dir;

        // Prepended, so it answers before Composer would load the bundled copy.
        spl_autoload_register([self::class, 'load'], true, true);
    }

    protected static function exists($class)
    {
        return class_exists($class, false) || interface_exists($class, false) || trait_exists($class, false);
    }

    /** The clone in use, or null when MailOptin runs its bundled copy. */
    public static function dir()
    {
        return self::$dir;
    }

    public static function load($class)
    {
        if (0 === strpos($class, self::OURS)) {
            // Loading the package's class gives it MailOptin's name too (below).
            self::load(self::PACKAGE . substr($class, strlen(self::OURS)));

            return;
        }

        if (0 !== strpos($class, self::PACKAGE)) return;

        $relative = substr($class, strlen(self::PACKAGE));
        $file     = self::$dir . '/src/' . str_replace('\\', '/', $relative) . '.php';

        if ( ! self::exists($class) && is_file($file)) require $file;

        // Straight away, not when MailOptin's name is first asked for: PHP checks a type hint or
        // instanceof against classes already loaded and never autoloads for it, so a package class
        // created under its own name (by Senders::make(), say) must already answer to MailOptin's.
        if (self::exists($class) && ! self::exists(self::OURS . $relative)) {
            class_alias($class, self::OURS . $relative);
        }
    }
}
