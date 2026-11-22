<?php

namespace MailOptin\Tests\Core\Sending;

use MailOptin\Core\Sending\PackageSource;
use WP_UnitTestCase;

/**
 * MAILOPTIN_EMAIL_SENDERS_DIR: a clone of wp-email-senders standing in for the bundled copy. Uses a
 * throwaway package with made-up class names, so the real classes stay as they are.
 */
class PackageSourceTest extends WP_UnitTestCase
{
    protected static $fake;

    public static function wpSetUpBeforeClass()
    {
        self::$fake = sys_get_temp_dir() . '/mo-fake-senders-' . getmypid();
        @mkdir(self::$fake . '/src/Probe', 0777, true);
        file_put_contents(self::$fake . '/src/Senders.php', '<?php');
        file_put_contents(self::$fake . '/src/Probe/ProbeInterface.php', '<?php namespace MailOptin\EmailSenders\Probe; interface ProbeInterface {}');
        file_put_contents(self::$fake . '/src/Probe/ProbeSender.php', '<?php namespace MailOptin\EmailSenders\Probe; class ProbeSender implements ProbeInterface { public static function make() { return new self(); } }');
    }

    public static function wpTearDownAfterClass()
    {
        array_map('unlink', glob(self::$fake . '/src/Probe/*.php'));
        @unlink(self::$fake . '/src/Senders.php');
        @rmdir(self::$fake . '/src/Probe');
        @rmdir(self::$fake . '/src');
        @rmdir(self::$fake);
    }

    protected function use_clone($dir)
    {
        $property = new \ReflectionProperty(PackageSource::class, 'dir');
        $property->setAccessible(true);
        $property->setValue(null, $dir);
    }

    public function tear_down()
    {
        $this->use_clone(null);
        parent::tear_down();
    }

    public function test_a_class_made_under_the_packages_own_name_satisfies_mailoptins_type_hints()
    {
        $this->use_clone(self::$fake);
        spl_autoload_register([PackageSource::class, 'load'], true, true);

        // As Senders::make() does: the package creates its own class, under its own name.
        $sender = \MailOptin\EmailSenders\Probe\ProbeSender::make();

        // PHP doesn't autoload for these, so MailOptin's names must already exist.
        $this->assertTrue(interface_exists('MailOptin\Core\EmailSenders\Probe\ProbeInterface', false));
        $this->assertInstanceOf('MailOptin\Core\EmailSenders\Probe\ProbeInterface', $sender);
        $accepts = function (\MailOptin\Core\EmailSenders\Probe\ProbeInterface $s) {
            return true;
        };
        $this->assertTrue($accepts($sender));

        spl_autoload_unregister([PackageSource::class, 'load']);
    }

    public function test_mailoptins_name_loads_the_clones_class()
    {
        $this->use_clone(self::$fake);
        spl_autoload_register([PackageSource::class, 'load'], true, true);

        $this->assertTrue(class_exists('MailOptin\Core\EmailSenders\Probe\ProbeSender'));
        $this->assertSame(self::$fake . '/src/Probe/ProbeSender.php', (new \ReflectionClass('MailOptin\Core\EmailSenders\Probe\ProbeSender'))->getFileName());

        spl_autoload_unregister([PackageSource::class, 'load']);
    }

    public function test_without_the_constant_the_bundled_copy_is_used()
    {
        $this->assertFalse(defined(PackageSource::CONSTANT));
        $this->assertNull(PackageSource::dir());
        $this->assertStringContainsString('/src/EmailSenders/Senders.php', (new \ReflectionClass('MailOptin\Core\EmailSenders\Senders'))->getFileName());
    }
}
