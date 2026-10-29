<?php

namespace MailOptin\Tests\Core\Audience;

use MailOptin\Core\DBUpdates;
use MailOptin\Core\Repositories\CustomFieldsRepository as Fields;
use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use MailOptin\Core\Repositories\TagsRepository as Tags;
use WP_UnitTestCase;

class TagsFieldsTest extends WP_UnitTestCase
{
    public function set_up()
    {
        parent::set_up();

        DBUpdates::get_instance()->update_routine_17();
        DBUpdates::get_instance()->update_routine_20();
        update_option('mo_db_ver', DBUpdates::DB_VER);

        Fields::flush_cache();
    }

    public function tear_down()
    {
        Fields::flush_cache();

        parent::tear_down();
    }

    protected function subscriber($email, $names = [])
    {
        return Subscribers::create(array_merge(['email' => $email, 'status' => Subscribers::STATUS_SUBSCRIBED], $names));
    }

    protected function field($type, $label, $options = [])
    {
        $id = Fields::create(['type' => $type, 'label' => $label, 'options' => $options]);
        $this->assertIsInt($id, is_wp_error($id) ? $id->get_error_message() : '');

        return Fields::get($id);
    }

    protected function ids($args)
    {
        return array_map('intval', wp_list_pluck(Subscribers::query($args + ['per_page' => 100])['items'], 'id'));
    }

    public function test_tag_names_are_unique_ignoring_case_and_trimmed()
    {
        $vip = Tags::create('  VIP  ');

        $this->assertSame($vip, Tags::create('vip'));
        $this->assertSame('VIP', Tags::get($vip)['name']);
        $this->assertTrue(is_wp_error(Tags::create('   ')));
        $this->assertSame(1, Tags::count());
    }

    public function test_ensure_creates_missing_tags_and_accepts_existing_ids()
    {
        $roses = Tags::create('roses');

        $ids = Tags::ensure([$roses, 'seeds', 'Seeds', 99999]);

        $this->assertCount(2, $ids);
        $this->assertSame($roses, $ids[0]);
        $this->assertSame('seeds', Tags::get($ids[1])['name']);
    }

    public function test_add_and_remove_tags_report_only_real_changes()
    {
        $a    = $this->subscriber('a@example.com');
        $b    = $this->subscriber('b@example.com');
        $vip  = Tags::create('vip');
        $seed = Tags::create('seeds');

        Tags::add_to_subscribers([$vip], [$a]);
        $added = Tags::add_to_subscribers([$vip, $seed], [$a, $b]);

        $this->assertSame([$a => [$seed], $b => [$vip, $seed]], $added);
        $this->assertSame([$seed => 'seeds', $vip => 'vip'], Tags::for_subscriber($a));

        $removed = Tags::remove_from_subscribers([$vip], [$a, $b]);
        $this->assertSame([$a => [$vip], $b => [$vip]], $removed);
        $this->assertSame([], Tags::remove_from_subscribers([$vip], [$a]));
    }

    public function test_rename_refuses_an_existing_name_and_merge_moves_subscribers()
    {
        $a     = $this->subscriber('a@example.com');
        $b     = $this->subscriber('b@example.com');
        $old   = Tags::create('customers');
        $new   = Tags::create('customer');

        Tags::add_to_subscribers([$old], [$a, $b]);
        Tags::add_to_subscribers([$new], [$a]);

        $this->assertTrue(is_wp_error(Tags::rename($old, 'Customer')));
        $this->assertTrue(Tags::rename($old, 'old customers'));

        $this->assertTrue(Tags::merge($old, $new));
        $this->assertNull(Tags::get($old));
        $this->assertSame([$new => 'customer'], Tags::for_subscriber($a));
        $this->assertSame([$new => 'customer'], Tags::for_subscriber($b));
        $this->assertSame(2, (int)wp_list_pluck(Tags::all(), 'subscribers', 'id')[$new]);
    }

    public function test_deleting_a_subscriber_removes_their_tags_and_values()
    {
        $a     = $this->subscriber('a@example.com');
        $vip   = Tags::create('vip');
        $field = $this->field(Fields::TYPE_TEXT, 'Company');

        Tags::add_to_subscribers([$vip], [$a]);
        Fields::set_values($a, ['company' => 'Acme']);

        Subscribers::delete($a);

        $this->assertSame(0, (int)wp_list_pluck(Tags::all(), 'subscribers', 'id')[$vip]);
        $this->assertSame([], Fields::value_counts());
        $this->assertNotNull(Fields::get($field['id']));
    }

    public function test_merge_keys_are_made_from_labels_and_validated()
    {
        $this->assertSame('favourite_plant_family', Fields::key_from_label('Favourite plant family'));
        $this->assertSame('cafe_name', Fields::key_from_label('Café name!'));
        $this->assertSame('field_2nd_phone', Fields::key_from_label('2nd phone'));

        $this->assertSame('mo_field_key_reserved', Fields::create(['type' => 'text', 'label' => 'Email'])->get_error_code());
        $this->assertSame('mo_field_key', Fields::create(['type' => 'text', 'label' => 'X', 'merge_key' => 'Bad Key'])->get_error_code());
        $this->assertSame('mo_field_options', Fields::create(['type' => 'dropdown', 'label' => 'Size', 'options' => "\n \n"])->get_error_code());
        $this->assertSame('mo_field_type', Fields::create(['type' => 'html', 'label' => 'X'])->get_error_code());

        $this->field(Fields::TYPE_TEXT, 'Company');
        $this->assertSame('mo_field_key_taken', Fields::create(['type' => 'number', 'label' => 'Company'])->get_error_code());
    }

    public function test_update_keeps_the_merge_key_and_type()
    {
        $field = $this->field(Fields::TYPE_DROPDOWN, 'Size', "Small\nLarge\nsmall");

        $this->assertSame(['Small', 'Large'], $field['options']);

        Fields::update($field['id'], ['label' => 'Garden size', 'merge_key' => 'other', 'type' => 'text', 'options' => ['S', 'M', 'L']]);

        $field = Fields::get($field['id']);
        $this->assertSame('Garden size', $field['label']);
        $this->assertSame('size', $field['merge_key']);
        $this->assertSame('dropdown', $field['type']);
        $this->assertSame(['S', 'M', 'L'], $field['options']);
    }

    public function test_values_are_validated_and_normalized_by_type()
    {
        $cases = [
            [Fields::TYPE_NUMBER, [], '1,000.5', '1000.5'],
            [Fields::TYPE_NUMBER, [], '2,5', '2.5'],
            [Fields::TYPE_NUMBER, [], '7', '7'],
            [Fields::TYPE_NUMBER, [], 'seven', null],
            [Fields::TYPE_DATE, [], 'June 2, 1988', '1988-06-02'],
            [Fields::TYPE_DATE, [], 'soon', null],
            [Fields::TYPE_CHECKBOX, [], 'on', '1'],
            [Fields::TYPE_CHECKBOX, [], 'no', '0'],
            [Fields::TYPE_CHECKBOX, [], 'maybe', null],
            [Fields::TYPE_DROPDOWN, ['Small', 'Large'], 'large', 'Large'],
            [Fields::TYPE_DROPDOWN, ['Small', 'Large'], 'Huge', null],
            [Fields::TYPE_MULTISELECT, ['Roses', 'Fruit', 'Veg'], 'veg, roses', '["Roses","Veg"]'],
            [Fields::TYPE_MULTISELECT, ['Roses', 'Fruit', 'Veg'], ['Fruit'], '["Fruit"]'],
            [Fields::TYPE_MULTISELECT, ['Roses'], 'Tulips', null],
            [Fields::TYPE_URL, [], 'example.com/shop', 'https://example.com/shop'],
            [Fields::TYPE_URL, [], 'javascript:alert(1)', null],
            [Fields::TYPE_URL, [], 'not a url', null],
            [Fields::TYPE_PHONE, [], '+1 (555) 010-2030', '+1 (555) 010-2030'],
            [Fields::TYPE_PHONE, [], 'call me', null],
            [Fields::TYPE_TEXT, [], '<b>Acme</b> Ltd', 'Acme Ltd'],
        ];

        foreach ($cases as $i => list($type, $options, $raw, $expected)) {
            $field = ['type' => $type, 'label' => 'Field', 'options' => $options];
            $value = Fields::normalize_value($field, $raw);

            if (null === $expected) {
                $this->assertTrue(is_wp_error($value), "case $i should be refused");
            } else {
                $this->assertSame($expected, $value['text'], "case $i");
            }
        }

        $this->assertNull(Fields::normalize_value(['type' => 'text', 'label' => 'X', 'options' => []], '  '));
    }

    public function test_set_values_tracks_changes_errors_and_only_empty()
    {
        $a       = $this->subscriber('a@example.com');
        $company = $this->field(Fields::TYPE_TEXT, 'Company');
        $orders  = $this->field(Fields::TYPE_NUMBER, 'Orders');

        $result = Fields::set_values($a, ['company' => 'Acme', 'orders' => 'lots', 'unknown' => 'x']);
        $this->assertSame([$company['id'] => [null, 'Acme']], $result['changed']);
        $this->assertArrayHasKey($orders['id'], $result['errors']);

        // A form signup only fills in what's missing.
        $result = Fields::set_values($a, ['company' => 'Evil Corp', 'orders' => '3'], true);
        $this->assertSame([$orders['id'] => [null, '3']], $result['changed']);
        $this->assertSame('Acme', Fields::get_values($a)[$company['id']]['value_text']);

        // Unchanged values aren't reported; clearing is.
        $this->assertSame([], Fields::set_values($a, [$company['id'] => 'Acme'])['changed']);
        $this->assertSame([$company['id'] => ['Acme', null]], Fields::set_values($a, ['company' => ''])['changed']);
    }

    public function test_bulk_set_value()
    {
        $a     = $this->subscriber('a@example.com');
        $b     = $this->subscriber('b@example.com');
        $zone  = $this->field(Fields::TYPE_DROPDOWN, 'Zone', ['6a', '7b']);
        $score = $this->field(Fields::TYPE_NUMBER, 'Score');

        $this->assertTrue(is_wp_error(Fields::set_value_for_subscribers($zone['id'], '9z', [$a, $b])));
        $this->assertSame(2, Fields::set_value_for_subscribers($zone['id'], '7B', [$a, $b]));
        $this->assertSame(2, Fields::set_value_for_subscribers($score['id'], '12.5', [$a, $b]));

        $values = Fields::values_for_subscribers([$a, $b]);
        $this->assertSame('7b', $values[$b][$zone['id']]['value_text']);
        $this->assertEquals(12.5, $values[$a][$score['id']]['value_number']);

        Fields::set_value_for_subscribers($zone['id'], '', [$a]);
        $this->assertArrayNotHasKey($zone['id'], Fields::get_values($a));
    }

    public function test_merge_values_and_display()
    {
        $a = $this->subscriber('a@example.com');
        $this->field(Fields::TYPE_CHECKBOX, 'Wants SMS');
        $this->field(Fields::TYPE_MULTISELECT, 'Plants', ['Roses', 'Veg']);
        $this->field(Fields::TYPE_TEXT, 'Company');

        Fields::set_values($a, ['wants_sms' => 'yes', 'plants' => ['Veg', 'Roses']]);

        $this->assertSame(['wants_sms' => 'Yes', 'plants' => 'Roses, Veg', 'company' => ''], Fields::merge_values($a));
    }

    public function test_reorder_and_delete()
    {
        $one   = $this->field(Fields::TYPE_TEXT, 'One');
        $two   = $this->field(Fields::TYPE_TEXT, 'Two');
        $three = $this->field(Fields::TYPE_TEXT, 'Three');
        $a     = $this->subscriber('a@example.com');

        Fields::reorder([$three['id'], $one['id']]);
        $this->assertSame(['three', 'one', 'two'], wp_list_pluck(Fields::all(), 'merge_key'));

        Fields::set_values($a, ['two' => 'x']);
        Fields::delete($two['id']);
        $this->assertSame([], Fields::get_values($a));
        $this->assertSame(['three', 'one'], wp_list_pluck(Fields::all(), 'merge_key'));
    }

    public function test_query_filters_by_tags()
    {
        $a     = $this->subscriber('a@example.com');
        $b     = $this->subscriber('b@example.com');
        $c     = $this->subscriber('c@example.com');
        $roses = Tags::create('roses');
        $vip   = Tags::create('vip');

        Tags::add_to_subscribers([$roses], [$a, $b]);
        Tags::add_to_subscribers([$vip], [$b]);

        $sorted = function ($ids) {
            sort($ids);

            return $ids;
        };

        $this->assertSame([$a, $b], $sorted($this->ids(['tag_ids' => [$roses, $vip]])));
        $this->assertSame([$b], $this->ids(['tag_ids' => [$roses, $vip], 'tag_match' => 'all']));
        $this->assertSame([$c], $this->ids(['tag_ids' => [$roses], 'tag_match' => 'none']));

        $items = Subscribers::query(['tag_ids' => [$vip]])['items'];
        $this->assertSame([$roses => 'roses', $vip => 'vip'], $items[0]['tags']);
    }

    public function test_query_filters_by_field_conditions_and_search_matches_values()
    {
        $a = $this->subscriber('a@example.com');
        $b = $this->subscriber('b@example.com');
        $c = $this->subscriber('c@example.com');

        $orders  = $this->field(Fields::TYPE_NUMBER, 'Orders');
        $born    = $this->field(Fields::TYPE_DATE, 'Birthday');
        $sms     = $this->field(Fields::TYPE_CHECKBOX, 'Wants SMS');
        $zone    = $this->field(Fields::TYPE_DROPDOWN, 'Zone', ['6a', '7b', '8a']);
        $plants  = $this->field(Fields::TYPE_MULTISELECT, 'Plants', ['Roses', 'Veg']);
        $company = $this->field(Fields::TYPE_TEXT, 'Company');

        Fields::set_values($a, ['orders' => '2', 'birthday' => '1990-01-01', 'wants_sms' => '1', 'zone' => '6a', 'plants' => 'Roses', 'company' => 'Okafor Landscaping']);
        Fields::set_values($b, ['orders' => '10', 'birthday' => '2000-05-05', 'wants_sms' => '0', 'zone' => '8a', 'plants' => 'Roses, Veg']);

        $filter = function ($field, $op, $value = '', $value2 = '') {
            return $this->ids(['field_conditions' => [['field_id' => $field['id'], 'op' => $op, 'value' => $value, 'value2' => $value2]], 'orderby' => 'email', 'order' => 'ASC']);
        };

        $this->assertSame([$b], $filter($orders, 'gt', '9'));
        $this->assertSame([$a, $b], $filter($orders, 'between', '20', '1'));
        $this->assertSame([$c], $filter($orders, 'empty'));
        $this->assertSame([$a], $filter($born, 'before', '1995-01-01'));
        $this->assertSame([$a], $filter($sms, 'checked'));
        $this->assertSame([$b, $c], $filter($sms, 'unchecked'));
        $this->assertSame([$a, $b], $filter($zone, 'is', ['6a', '8a']));
        $this->assertSame([$b, $c], $filter($zone, 'is_not', ['6a']));
        $this->assertSame([$b], $filter($plants, 'has_any', ['Veg']));
        $this->assertSame([$c], $filter($plants, 'has_none', ['Roses']));
        $this->assertSame([$a], $filter($company, 'contains', 'okafor'));

        // Incomplete or unknown conditions are ignored rather than matching nobody.
        $this->assertSame([$a, $b, $c], $filter($orders, 'gt', 'abc'));
        $this->assertSame([$a, $b, $c], $filter($orders, 'contains', 'x'));
        $this->assertSame([$a, $b, $c], $filter($zone, 'is', ['Mars']));

        $this->assertSame([$a], $this->ids(['search' => 'Landscaping']));
    }

    public function test_fresh_install_creates_the_tables()
    {
        global $wpdb;

        \MailOptin\Core\RegisterActivation\CreateDBTables::make();

        foreach (['mo_tags', 'mo_subscriber_tags', 'mo_custom_fields', 'mo_subscriber_field_values'] as $table) {
            $this->assertNotEmpty($wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}$table'") ?: $wpdb->get_results("DESCRIBE {$wpdb->prefix}$table"), $table);
        }
    }
}
