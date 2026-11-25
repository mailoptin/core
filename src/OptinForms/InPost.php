<?php

namespace MailOptin\Core\OptinForms;

use MailOptin\Core\Admin\Customizer\OptinForm\OptinFormFactory;
use MailOptin\Core\Repositories\OptinCampaignsRepository as Repository;
use MailOptin\Core\Repositories\OptinCampaignsRepository;


class InPost
{
    use PageTargetingRuleTrait, UserTargetingRuleTrait, QueryStringTargetingRuleTrait;

    public function __construct()
    {
        add_filter('the_content', [$this, 'insert_optin'], PHP_INT_MAX - 5);
    }

    public function insert_optin($content)
    {
        // needed to prevent the optin from showing on post excerpt (on homepage / post listing)
        if (is_front_page() || ! is_singular()) return $content;

        if (isset($_GET['mohide']) && $_GET['mohide'] == 'true') return $content;

        $optin_ids = get_transient('mo_get_optin_ids_inpost_display');

        if ($optin_ids === false) {
            $optin_ids = Repository::get_inpost_optin_ids();
            set_transient('mo_get_optin_ids_inpost_display', $optin_ids, HOUR_IN_SECONDS);
        }

        \MailOptin\Core\Repositories\CampaignSettingsRepository::prime(\MailOptin\Core\Repositories\CampaignSettingsRepository::TYPE_OPTIN, $optin_ids);

        foreach ($optin_ids as $id) {

            $id = absint($id);

            do_action('mailoptin_before_inpost_optin_display_determinant', $id, $optin_ids);

            // if it is a split test variant, skip
            if (Repository::is_split_test_variant($id)) continue;

            // if optin is not enabled, pass.
            if ( ! Repository::is_activated($id)) continue;

            $id = Repository::choose_split_test_variant($id);

            $optin_position = Repository::get_merged_customizer_value($id, 'inpost_form_optin_position');

            // A position another part of the plugin draws (Premium: in place of WooCommerce's Add to
            // cart button) isn't put in the content; it uses display_variant() where it draws it. Any
            // other value still goes after the content, as before.
            if (in_array($optin_position, self::external_positions(), true)) continue;

            if ( ! OptinCampaignsRepository::is_test_mode($id)) {

                // if optin global exit/interaction and success cookie result fails, move to next.
                if ( ! Repository::global_cookie_check_result()) continue;

                if ( ! apply_filters('mailoptin_show_optin_form', true, $id)) continue;

                if ( ! $this->user_targeting_rule_checker($id)) {
                    continue;
                }

                if ( ! $this->page_level_targeting_rule_checker($id)) {
                    continue;
                }

                if ( ! $this->query_level_targeting_rule_checker($id)) {
                    continue;
                }
            }

            $optin_form = OptinFormFactory::build($id);

            if ('between_content' == $optin_position) {

                $content_array = explode('</p>', $content);

                // using array_values because we want the array key re-indexed.
                // see https://stackoverflow.com/a/20373067/2648410
                $content_array = array_values(
                    array_filter($content_array, function ($val) {
                        // useful to remove any redundant <p></p>
                        return ! empty(trim($val));
                    })
                );

                $content_length     = count($content_array);
                $content_first_half = ceil($content_length / 2);
                $content_to_return  = '';

                for ($i = 0; $i < $content_first_half; $i++) {
                    $content_to_return .= $content_array[$i] . '</p>';
                }

                $content_to_return .= $optin_form;

                for ($i = $content_first_half; $i < $content_length; $i++) {
                    $content_to_return .= $content_array[$i] . '</p>';
                }

                $content = $content_to_return;

            } elseif ('before_content' == $optin_position) {
                $content = $optin_form . $content;
            } else {
                $content .= $optin_form;
            }

            do_action('mailoptin_after_inpost_optin_display_determinant', $id, $optin_ids);
        }


        return $content;
    }

    /**
     * The positions inside a post's content.
     */
    public static function content_positions()
    {
        return ['before_content', 'between_content', 'after_content'];
    }

    /**
     * Positions drawn outside the content by whoever added them (to the position choices, through
     * mo_optin_form_customizer_inpost_form_optin_position_args), with display_variant().
     *
     * @return string[]
     */
    public static function external_positions()
    {
        return array_values(array_diff(array_map('strval', (array)apply_filters('mailoptin_inpost_external_positions', [])), self::content_positions()));
    }

    /**
     * Whether an in-post optin may show at a position on this request, with every check the content
     * insertion uses: activated, not hidden, cookies, the show filter and page, user and query targeting.
     * Split test variants each have their own position, so the one chosen must be set to this position.
     *
     * @param int $id
     * @param string $position e.g. the one passed to mailoptin_inpost_external_positions
     *
     * @return int the optin to show (the split test variant chosen), or 0
     */
    public function display_variant($id, $position)
    {
        $id = absint($id);

        if ( ! $id || Repository::is_split_test_variant($id) || ! Repository::is_activated($id)) return 0;

        if (isset($_GET['mohide']) && $_GET['mohide'] == 'true') return 0;

        $id = absint(Repository::choose_split_test_variant($id));

        if ((string)Repository::get_merged_customizer_value($id, 'inpost_form_optin_position') !== (string)$position) return 0;

        if ( ! OptinCampaignsRepository::is_test_mode($id)) {
            if ( ! Repository::global_cookie_check_result()) return 0;
            if ( ! apply_filters('mailoptin_show_optin_form', true, $id)) return 0;
            if ( ! $this->user_targeting_rule_checker($id)) return 0;
            if ( ! $this->page_level_targeting_rule_checker($id)) return 0;
            if ( ! $this->query_level_targeting_rule_checker($id)) return 0;
        }

        return $id;
    }

    public static function get_instance()
    {
        static $instance = null;

        if (is_null($instance)) {
            $instance = new self();
        }

        return $instance;
    }

}