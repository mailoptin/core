<?php
/**
 * Copyright (C) 2016  Agbonghama Collins <me@w3guy.com>
 */

namespace MailOptin\Tests\Core\Triggers;

use WP_UnitTestCase;

class NewPublishPostTest extends WP_UnitTestCase
{
    public $insert_data;

    public function set_up()
    {
        parent::set_up();
    }

    public function tear_down()
    {
        parent::tear_down();
    }

    public function testNewPublishPost()
    {
        // Create post object
        $post_id = wp_insert_post(
            array(
                'post_title' => 'sample post',
                'post_content' => 'sample post content',
                'post_status' => 'publish',
                'post_author' => 1,
            )
        );

        $this->assertIsInt($post_id);
        $this->assertSame('publish', get_post_status($post_id));
    }

//    function test_single() {
//        $this->go_to( get_permalink( 1 ) );
//        $this->assertTrue( is_single(), 'This is not a single post page.' );
//        $this->assertTrue( have_posts() );
//    }

    function test_404()
    {
        $this->go_to(site_url('?p=100'));
        $this->assertTrue(is_404());
    }

}