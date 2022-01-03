<?php

namespace MailOptin\Core\Admin\SettingsPage;

// Exit if accessed directly
if ( ! defined('ABSPATH')) {
    exit;
}

use MailOptin\Core\Repositories\ConnectionsRepository;
use MailOptin\Core\Repositories\EmailCampaignRepository;
use MailOptin\Core\Repositories\EmailTemplatesRepository;
use W3Guy\Custom_Settings_Page_Api;

class AddNewsletter extends AbstractSettingsPage
{
    public $saved_values = [];
    /**
     * Back to campaign overview button.
     */
    public function back_to_optin_overview()
    {
        $url = MAILOPTIN_EMAIL_CAMPAIGNS_SETTINGS_PAGE;
        echo "<a class=\"add-new-h2\" href=\"$url\">" . __('Back to Overview', 'mailoptin') . '</a>';
    }

    /**
     * Sub-menu header for optin theme types.
     */
    public function add_email_campaign_settings_header()
    {
        if ( ! empty($_GET['page']) && $_GET['page'] == MAILOPTIN_EMAIL_CAMPAIGNS_SETTINGS_SLUG) {
            ?>
            <div class="mailoptin-optin-new-list mailoptin-optin-clear">
                <strong><?php _e('Email Subject', 'mailoptin'); ?></strong>
                <input type="text" name="mailoptin-optin-campaign" id="mailoptin-add-campaign-title" style="width:45%;" placeholder="<?php _e('What is the subject line for this email?', 'mailoptin'); ?>">
            </div>
        <?php }
    }

    /**
     * Build the settings page structure. I.e tab, sidebar.
     */
    public function settings_admin_page()
    {
        add_action('wp_cspa_before_closing_header', [$this, 'back_to_optin_overview']);
//        add_action('wp_cspa_before_post_body_content', array($this, 'add_email_campaign_settings_header'), 10, 2);
        add_filter('wp_cspa_main_content_area', [$this, 'display_broadcast_template']);
//        add_filter('wp_cspa_main_content_area', [$this, 'available_email_templates']);

        $instance = Custom_Settings_Page_Api::instance();
        $instance->page_header(__('Create Broadcast', 'mailoptin'));
        $this->register_core_settings($instance);
        $instance->build(true, true);
    }
    
    /**
     *  Display the broadcast UI
     */
    public function display_broadcast_template()
    {
        $this->template_broadcast_tmpl(EmailCampaignRepository::NEWSLETTER);
    
        do_action('mo_campaign_broadcast_templates');
    }

    /**
     * Display available email template for selected campaign type.
     */
    public function available_email_templates()
    {
        $this->template_listing_tmpl(EmailCampaignRepository::NEWSLETTER);

        do_action('mo_campaign_available_newsletter_templates');
    }
    
    public function template_broadcast_tmpl($campaign_type)
    {
        wp_enqueue_script('mailoptin-customizer-integrations', MAILOPTIN_ASSETS_URL . 'js/customizer-controls/integration-control/control.js', array('jquery'), false, true);
        wp_enqueue_style('mailoptin-customizer-integrations', MAILOPTIN_ASSETS_URL . 'js/customizer-controls/integration-control/style.css', null);
        
      echo "<div class='mailoptin-optin-broadcast mailoptin-optin-clear'>";
        $this->email_provider_templates();
        
        $this->add_email_campaign_settings_header();
      echo "</div>";
    }

    public function template_listing_tmpl($campaign_type)
    {
        echo "<div id=\"notifType_{$campaign_type}\" class=\"mailoptin-email-templates mailoptin-template-clear\">";
        foreach (EmailTemplatesRepository::get_by_type($campaign_type) as $email_template) {

            $template_name  = $email_template['name'];
            $template_class = $email_template['template_class'];
            $screenshot     = $email_template['screenshot'];
            ?>
            <div id="mailoptin-email-template-list"
                 class="mailoptin-email-template mailoptin-email-template-<?php echo $template_class; ?>"
                 data-email-template="<?php echo $template_class; ?>"
                 data-campaign-type="<?php echo $campaign_type; ?>">
                <div class="mailoptin-email-template-screenshot">
                    <img src="<?php echo $screenshot; ?>" alt="<?php echo $template_name; ?>">
                </div>
                <h3 class="mailoptin-email-template-name"><?php echo $template_name . ' ' . __('Template', 'mailoptin'); ?></h3>
                <div class="mailoptin-email-template-actions">
                    <a class="button button-primary mailemail-template-select"
                       data-email-template="<?php echo $template_class; ?>"
                       data-campaign-type="<?php echo $campaign_type; ?>"
                       title="<?php _e('Select this template', 'mailoptin'); ?>">
                        <?php _e('Select Template', 'mailoptin'); ?>
                    </a>
                </div>
            </div>
            <?php
        }
        $this->code_your_own_box($campaign_type);
        echo '</div>';
    }

    public function code_your_own_box($campaign_type)
    {
        $label = __('Code Your Own', 'mailoptin');
        ?>
        <div id="mailoptin-email-template-list"
             class="mailoptin-email-template"
             data-email-template="HTML"
             data-campaign-type="<?php echo $campaign_type; ?>">
            <div class="mailoptin-email-template-screenshot">
                <img src="<?php echo MAILOPTIN_ASSETS_URL . 'images/email-templates/code-your-own.jpg' ?>" alt="<?php echo $label; ?>">
            </div>
            <h3 class="mailoptin-email-template-name" style="visibility:hidden"><?php echo $label; ?></h3>
            <div class="mailoptin-email-template-actions">
                <a class="button button-primary mailemail-template-select"
                   data-email-template="<?php echo EmailCampaignRepository::CODE_YOUR_OWN_TEMPLATE; ?>"
                   data-campaign-type="<?php echo $campaign_type; ?>"
                   title="<?php echo $label; ?>">
                    <?php echo $label; ?>
                </a>
            </div>
        </div>
        <?php
    }
    
    public function email_provider_templates($index = 9999999999999)
    {
        $email_providers = ConnectionsRepository::get_connections();
        
        $widget_title          = __('New Integration', 'mailoptin');
        $connection_email_list = ['' => __('Select...', 'mailoptin')];
        if (isset($this->saved_values[$index]['connection_service'])) {
            $saved_email_provider = $this->saved_values[$index]['connection_service'];
            if ( ! empty($email_providers[$saved_email_provider])) {
                $widget_title = $email_providers[$saved_email_provider];
            }
            // prepend 'Select...' to the array of email list.
            // because select control will be hidden if no choice is found.
            $connection_email_list = $connection_email_list + ConnectionsRepository::connection_email_list($saved_email_provider);
        }
        ?>
      <div class="mo-integration-widget mo-integration-part-widget" data-integration-index="<?= $index; ?>">
        <div class="mo-integration-widget-top mo-integration-part-widget-top ui-sortable-handle">
          <div class="mo-integration-part-widget-title-action">
            <button type="button" class="mo-integration-widget-action">
              <span class="toggle-indicator"></span>
            </button>
          </div>
          <div class="mo-integration-widget-title">
            <h3><?= $widget_title; ?></h3>
          </div>
        </div>
        <div class="mo-integration-widget-content">
          <div class="mo-integration-widget-form">
              <?php $this->select_field($index, 'connection_service', $email_providers, '', __('Select Integration', 'mailoptin'));?>
          </div>
        </div>
      </div>
        
        <?php
    }
    
    public function controls($classInstance) {
    
    }
    
    public function select_field($index, $name, $choices, $class = '', $label = '', $description = '')
    {
        if (empty($choices)) return;
        
        if ( ! isset($index) || ! array_key_exists($index, $this->saved_values)) {
            $index = '{mo-integration-index}';
        }
        
        $default     = isset($this->default_values[$name]) ? $this->default_values[$name] : '';
        $saved_value = isset($this->saved_values[$index][$name]) ? $this->saved_values[$index][$name] : $default;
        
        $random_id = wp_generate_password(5, false) . '_' . $index;
        
        if ( ! empty($class)) {
            $class = " $class";
        }
        
        echo "<div class=\"$name mo-integration-block{$class}\">";
        if ( ! empty($label)) : ?>
          <label for="<?php echo $random_id ?>" class="customize-control-title"><?php echo esc_html($label); ?></label>
        <?php endif; ?>
      <select id="<?php echo $random_id ?>" class="mo-optin-integration-field" name="<?php echo $name ?>">
          <?php
              foreach ($choices as $value => $label) {
                  echo '<option value="' . esc_attr($value) . '"' . selected($saved_value, $value, false) . '>' . $label . '</option>';
              }
          ?>
      </select>
        <?php if ( ! empty($description)) : ?>
      <span class="description customize-control-description"><?php echo $description; ?></span>
    <?php endif;
        echo '</div>';
    }
    
    /**
     * @return AddNewsletter
     */
    public static function get_instance()
    {
        static $instance = null;

        if (is_null($instance)) {
            $instance = new self();
        }

        return $instance;
    }
}