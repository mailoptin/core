<?php

namespace MailOptin\Core\EmailCampaigns\PostsEmailDigest\Templates;


use MailOptin\Core\EmailCampaigns\PostsEmailDigest\AbstractTemplate;
use MailOptin\Core\Repositories\EmailCampaignRepository;

class Lucid extends AbstractTemplate
{
    public $template_name = 'Lucid';

    public $column_count;

    public function __construct($email_campaign_id, $posts)
    {
        // -------------- Template header logo width and height dimension --------------------------------- //
        add_filter('mailoptin_template_customizer_header_logo_args', function ($args) {
            $args['width']  = 308;
            $args['height'] = 48;

            return $args;
        });

        add_filter('mailoptin_customizer_email_campaign_post_content_length', function () {
            return 60;
        });

        parent::__construct($email_campaign_id, $posts);

        $this->column_count = (int)EmailCampaignRepository::get_customizer_value($this->email_campaign_id, 'column_count', '1');

        if (empty($this->column_count) || $this->column_count <= 1 || $this->column_count > 3) $this->column_count = 1;
    }

    /**
     * Default template values.
     *
     * @return array
     */
    public function default_customizer_values()
    {
        return [
                'page_background_color'                    => '#f2f4f6',
                'header_text_color'                        => '#bbbfc3',
                'header_web_version_link_color'            => '#74787e',
                'content_background_color'                 => '#ffffff',
                'content_text_color'                       => '#74787e',
                'content_headline_color'                   => '#2F3133',
                'content_ellipsis_button_background_color' => '#dc4d2f',
                'content_ellipsis_button_text_color'       => '#ffffff',
                'footer_text_color'                        => '#aeaeae',
                'footer_unsubscribe_link_color'            => '#74787e',
        ];
    }

    /**
     * @param mixed $settings
     *
     * @return mixed
     */
    public function customizer_page_settings($settings)
    {
        return $settings;
    }

    /**
     * @param array $controls
     * @param \WP_Customize_Manager $wp_customize
     * @param string $option_prefix
     * @param \MailOptin\Core\Admin\Customizer\EmailCampaign\Customizer $customizerClassInstance
     *
     * @return array
     */
    public function customizer_page_controls($controls, $wp_customize, $option_prefix, $customizerClassInstance)
    {
        return $controls;
    }

    /**
     * {@inheritdoc}
     *
     * @param mixed $header_settings
     *
     * @return mixed
     */
    public function customizer_header_settings($header_settings)
    {
        unset($header_settings['header_background_color']);

        return $header_settings;
    }

    /**
     * {@inheritdoc}
     *
     * @param array $header_controls
     * @param \WP_Customize_Manager $wp_customize
     * @param string $option_prefix
     * @param \MailOptin\Core\Admin\Customizer\EmailCampaign\Customizer $customizerClassInstance
     *
     * @return array
     */
    public function customizer_header_controls($header_controls, $wp_customize, $option_prefix, $customizerClassInstance)
    {
        unset($header_controls['header_background_color']);

        return $header_controls;
    }

    /**
     * {@inheritdoc}
     *
     * @param mixed $content_settings
     *
     * @return mixed
     */
    public function customizer_content_settings($content_settings)
    {
        $content_settings['column_count'] = [
                'default'   => '1',
                'type'      => 'option',
                'transport' => 'refresh',
        ];

        return $content_settings;
    }

    /**
     * {@inheritdoc}
     *
     * @param array $content_controls
     * @param \WP_Customize_Manager $wp_customize
     * @param string $option_prefix
     * @param \MailOptin\Core\Admin\Customizer\EmailCampaign\Customizer $customizerClassInstance
     *
     * @return array
     */
    public function customizer_content_controls($content_controls, $wp_customize, $option_prefix, $customizerClassInstance)
    {
        $content_controls['column_count'] = array(
                'label'    => __('Columns', 'mailoptin'),
                'section'  => $customizerClassInstance->campaign_content_section_id,
                'settings' => $option_prefix . '[column_count]',
                'type'     => 'select',
                'choices'  => array(
                        '1' => __('One', 'mailoptin'),
                        '2' => __('Two', 'mailoptin'),
                        '3' => __('Three', 'mailoptin'),
                ),
                'priority' => 21
        );

        return $content_controls;
    }

    /**
     * {@inheritdoc}
     *
     * @param mixed $footer_settings
     *
     * @return mixed
     */
    public function customizer_footer_settings($footer_settings)
    {
        unset($footer_settings['footer_background_color']);

        return $footer_settings;
    }


    /**
     * {@inheritdoc}
     *
     * @param array $footer_controls
     * @param \WP_Customize_Manager $wp_customize
     * @param string $option_prefix
     * @param \MailOptin\Core\Admin\Customizer\EmailCampaign\Customizer $customizerClassInstance
     *
     * @return array
     */
    public function customizer_footer_controls($footer_controls, $wp_customize, $option_prefix, $customizerClassInstance)
    {
        unset($footer_controls['footer_background_color']);

        return $footer_controls;
    }

    public function get_script()
    {
    }

    public function row_wrapper_start()
    {
        return '<table class="mo-posts-grid" cellpadding="0" cellspacing="0" border="0" width="100%" role="presentation"><tbody><tr>';
    }

    public function row_wrapper_end()
    {
        return '</tr></tbody></table>';
    }

    public function item_wrapper_start()
    {
        return '<td class="mo-post-cell" valign="top">';
    }

    public function item_wrapper_end()
    {
        return '</td>';
    }

    public function get_post_item_width()
    {
        return [2 => 235, 3 => 150][$this->column_count] ?? 500;
    }

    public function single_post_item()
    {
        $content_remove_post_link = EmailCampaignRepository::get_merged_customizer_value($this->email_campaign_id, 'content_remove_post_link');

        $content_ellipsis_button_background_color = $this->content_ellipsis_button_background_color();

        $width = $this->get_post_item_width();

        $content_ellipsis_button_label = $this->content_ellipsis_button_label();

        ob_start();
        ?>
        <table>
            <tbody>
            <tr>
                <td>
                    <?php if ($content_remove_post_link == false) : ?>
                        <a href="{{post.url}}">
                            <h1 class="mo-content-title-font-size mo-content-headline-color">{{post.title}}</h1>
                        </a>
                        {{post.meta}}
                        <a href="{{post.url}}">
                            <img class="mo-imgix" alt="{{post.feature.image.alt}}" src="{{post.feature.image}}" width="<?= $width ?>" style="max-width:<?= $width ?>px;margin:0 auto;">
                        </a>
                    <?php endif;

                    if ($content_remove_post_link == true) : ?>
                        <h1 class="mo-content-title-font-size mo-content-headline-color" style="margin-top:0;">
                            {{post.title}}</h1>
                        {{post.meta}}
                        <img class="mo-imgix" alt="{{post.feature.image.alt}}" src="{{post.feature.image}}" width="<?= $width ?>" style="max-width:<?= $width ?>px;margin:0 auto;">
                    <?php endif;
                    do_action('mailoptin_email_campaign_lucid_before_post_content');
                    ?>
                    {{post.content}}
                    <!-- Action -->
                    <table class="body-action mo-content-remove-ellipsis-button" width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation">
                        <tr>
                            <td align="center" class="mo-content-button-alignment" style="padding:0;">
                                <!--[if mso]>
            <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{post.url}}" style="height:45px;v-text-anchor:middle;width:200px;" arcsize="10%" stroke="f" fillcolor="<?= $content_ellipsis_button_background_color ?>">
                <w:anchorlock/>
                <center style="font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#ffffff;font-weight:bold;">
                    <?= $content_ellipsis_button_label ?>
                </center>
            </v:roundrect>
            <![endif]-->
                                <!--[if !mso]><!-->
                                <a class="button button--red mo-content-button-background-color mo-content-button-text-color mo-content-read-more-label" href="{{post.url}}"><?= $content_ellipsis_button_label ?></a>
                                <!--<![endif]-->
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
            </tbody>
        </table>
        <?php

        return ob_get_clean();
    }

    public function delimiter()
    {
        ob_start();
        ?>
        <!--[if !mso]><!-->
        <p style="border-top:solid 1px lightgrey;margin:10px auto 50px;width:100%;"></p>
        <!--<![endif]-->

        <!--[if mso | IE]>
        <table align="center" border="0" cellpadding="0" cellspacing="0" style="border-top:solid 1px lightgrey;margin:10px auto 50px;width:550px;" role="presentation" width="550px">
            <tr>
                <td style="height:0;line-height:0;">&nbsp;</td>
            </tr>
        </table>
        <![endif]-->
        <?php

        return ob_get_clean();
    }

    protected function get_footer_html()
    {
        $unsubscribe_link = apply_filters('mo_email_template_unsubscribe_link', '<a class="unsubscribe mo-footer-unsubscribe-link-label mo-footer-unsubscribe-link-color" href="{{unsubscribe}}">[mo_footer_unsubscribe_link_label]</a>');
        ob_start();
        ?>
        <tr class="mo-footer-container">
            <td style="padding:0;">
                <table class="email-footer mo-footer-text-color mo-footer-font-size" align="center" width="570" style="width:570px;" cellpadding="0" cellspacing="0" border="0" role="presentation">
                    <tr>
                        <td class="content-cell">
                            <p class="sub center mo-footer-copyright-line">[mo_footer_copyright_line]</p>
                            <p class="sub center mo-footer-description">[mo_footer_description]</p>
                            <p class="sub center">
                                <span class="unsubscribe-line mo-footer-unsubscribe-line">[mo_footer_unsubscribe_line]</span>
                                <?php echo $unsubscribe_link; ?>.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
        <?php
        return apply_filters('mo_ped_lucid_email_template_footer_html', ob_get_clean(), $this);
    }

    /**
     * Template body.
     *
     * @return string
     */
    public function get_body()
    {
        $column_count = $this->column_count;

        $view_web_version    = apply_filters('mo_email_template_view_web_version', '<a class="webversion-label mo-header-web-version-label mo-header-web-version-color" href="{{webversion}}" style="font-size:10px;">[mo_header_web_version_link_label]</a>');
        $before_main_content = EmailCampaignRepository::get_merged_customizer_value($this->email_campaign_id, 'content_before_main_content');
        $after_main_content  = EmailCampaignRepository::get_merged_customizer_value($this->email_campaign_id, 'content_after_main_content');
        $content             = $this->parsed_post_list($column_count);

        $body = <<<HTML
  <!--[if mso]>
  <style type="text/css">
    body, table, td {font-family: Arial, Helvetica, sans-serif !important;}
    table {border-collapse: collapse;}
    img {-ms-interpolation-mode: bicubic; border: 0;}
  </style>
  <![endif]-->
  <table class="email-wrapper mo-page-bg-color column-count-$column_count" width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation" style="margin:0;padding:0;">
    <tr>
      <td align="center" style="padding:0;">
        <table class="email-content" width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation" style="margin:0;padding:0;">
          <!-- Logo -->
          <tr class="mo-header-container">
            <td class="email-masthead">
            $view_web_version
            <br><br>
              <!--[if mso]>
              <table align="center" border="0" cellpadding="0" cellspacing="0" role="presentation"><tr><td style="text-align:center;">
              <![endif]-->
              <table cellpadding="0" cellspacing="0" border="0" role="presentation" style="display:inline-block;"><tr><td class="email-masthead_name mo-header-text mo-header-text-color">[mo_header_logo_text]</td></tr></table>
              <!--[if mso]>
              </td></tr></table>
              <![endif]-->
            </td>
          </tr>
          <!-- Email Body -->
          <tr>
            <td class="email-body mo-content-background-color" width="100%" style="margin:0;padding:0;">
              <!--[if mso]>
              <table align="center" border="0" cellpadding="0" cellspacing="0" width="570" role="presentation"><tr><td width="570" style="width:570px;">
              <![endif]-->
              <table class="email-body_inner mo-content-body-font-size mo-content-alignment" align="center" width="570" cellpadding="0" cellspacing="0" border="0" role="presentation" style="width:570px;max-width:570px;margin:0 auto;">
                <!-- Body content -->
                <tr>
                  <td class="content-cell mo-content-text-color" style="width:570px;max-width:570px;">
                  <table cellpadding="0" cellspacing="0" border="0" width="100%" role="presentation"><tbody><tr><td class="mo-before-main-content">$before_main_content</td></tr></tbody></table>
                  $content
                  <table cellpadding="0" cellspacing="0" border="0" width="100%" role="presentation"><tbody><tr><td class="mo-after-main-content">$after_main_content</td></tr></tbody></table>
                  </td>
                </tr>
              </table>
              <!--[if mso]>
              </td></tr></table>
              <![endif]-->
            </td>
          </tr>
          {$this->get_footer_html()}
        </table>
      </td>
    </tr>
  </table>
HTML;

        return apply_filters('mo_ped_lucid_email_template_body', $body, $this);
    }


    /**
     * Template CSS styling.
     *
     * @return string
     */
    public function get_styles()
    {
        return <<<CSS
    /* Base ------------------------------ */
    body {
      font-family: Arial, 'Helvetica Neue', Helvetica, sans-serif;
      -webkit-box-sizing: border-box;
      box-sizing: border-box;
      width: 100%;
      height: 100%;
      margin: 0;
      line-height: 1.4;
      color: #74787E;
      -webkit-text-size-adjust: none;
      mso-line-height-rule: exactly;
    }
    table {
      border-collapse: collapse;
      mso-table-lspace: 0pt;
      mso-table-rspace: 0pt;
    }
    td {
      mso-line-height-rule: exactly;
    }
    img {
      -ms-interpolation-mode: bicubic;
      border: 0;
      outline: none;
      text-decoration: none;
    }
    a {
      color: #3869D4;
    }

    /* Layout ------------------------------ */
    .email-wrapper {
      width: 100%;
      margin: 0;
      padding: 0;
    }
    .email-content {
      width: 100%;
      margin: 0;
      padding: 0;
    }

    /* Masthead ----------------------- */
    .email-masthead {
      padding: 25px 0;
      text-align: center;
    }
    .email-masthead a {
     font-size: 10px;
    }

    .email-masthead_logo {
      max-width: 400px;
      border: 0;
    }
    .email-masthead_name {
      font-size: 25px;
      font-weight: bold;
      text-decoration: none;
    }

    /* Body ------------------------------ */
    .email-body {
      width: 100%;
      margin: 0;
      padding: 0;
    }
    
    .email-body a {
      text-decoration: none;
    }
    
    .email-body .mo-content-button-alignment {
      margin-bottom: 10px;
    }

    .email-body img {
      max-width: 500px;
      max-height: 500px;
      width: 100%;
      height: auto;
      padding-bottom: 10px;
      display: block;
      margin: 0 auto;
      border: 0;
      outline: none;
      object-fit: cover;
    }
    
    .mo-post-cell tr td:only-child img {
      max-width: 100% !important;
    }
    
    .email-body figure {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
        width: 100% !important;
        max-width: 500px !important;
    }

    .email-body figcaption {
        max-width: 500px !important;
    }

    .email-body_inner {
      width: 570px;
      max-width: 570px;
      margin: 0 auto;
      padding: 0;
    }

    .email-footer {
      width: 570px;
      max-width: 570px;
      margin: 0 auto;
      padding: 0;
      text-align: center;
    }

    .body-action {
      width: 100%;
      margin: 30px auto 50px;
      padding: 0;
    }
    
    .mo-post-meta {
      color: #6f6f6f;
      font-size: 12px;
      font-weight: 400;
      line-height: 22px;
      mso-line-height-rule: exactly;
      padding: 0 0 5px 0;
    }

    .body-sub {
      margin-top: 25px;
      padding-top: 25px;
      border-top: 1px solid #EDEFF2;
    }

    .content-cell {
      padding: 35px;
    }

    .align-right {
      text-align: right;
    }

    /* Type ------------------------------ */

    h1 a {
      color: #2F3133;
      text-decoration: none;
    }

    h1 {
      margin-top: 0;
      color: #2F3133;
      font-weight: bold;
      font-size: 22px;
      line-height: 25px;
      mso-line-height-rule: exactly;
    }
    h2 {
      margin-top: 0;
      /*color: #2F3133;*/
      font-weight: bold;
      /*text-align: left;*/
    }
    h3 {
      margin-top: 0;
      /*color: #2F3133;*/
      font-weight: bold;
      /*text-align: left;*/
    }
    p {
      margin-top: 0;
      line-height: 21px;
      mso-line-height-rule: exactly;
    }
    
    p.center {
      text-align: center;
    }

    /* Buttons ------------------------------ */
    .button {
      display: inline-block;
      width: 100%;
      border-radius: 3px;
      font-size: 15px;
      line-height: 45px;
      text-align: center;
      text-decoration: none;
      -webkit-text-size-adjust: none;
      mso-hide: all;
    }

    /*Media Queries ------------------------------ */
    @media only screen and (max-width: 600px) {
      .email-body_inner,
      .email-footer {
        width: 100% !important;
      }
    }
    @media only screen and (max-width: 500px) {
      .button {
        width: 100% !important;
      }
    }
    
    pre {
        overflow: auto;
        border: 1px dashed #888;
        padding: 5px 10px;
        margin: 0;
        text-align: left;
        width: 500px;                          /* specify width  */
        white-space: pre-wrap;                 /* CSS3 browsers  */
        white-space: -moz-pre-wrap !important; /* 1999+ Mozilla  */
        white-space: -pre-wrap;                /* Opera 4 thru 6 */
        white-space: -o-pre-wrap;              /* Opera 7 and up */
        word-wrap: break-word;                 /* IE 5.5+ and up */
        }
        
        .mo-wc-price ins{
        text-decoration: none;
        }
        
        .mo-wc-price .screen-reader-text {
        display: none;
        max-height:0;
        overflow: hidden;
        color:transparent;
        font-size:1px;
        line-height: 1px;
        max-width:0;
        opacity:0;
        }
        
        .mo-post-cell .button {
            width: 100% !important;
        }
        
        .mo-posts-grid {
            width: 100%;
            border-collapse: collapse;
        }
        
        .mo-posts-grid td.mo-post-cell {
            vertical-align: top;
            padding: 0 8px;
            box-sizing: border-box;
        }

        .column-count-2  .mo-posts-grid td.mo-post-cell {
          width: 250px;
        }
        
      .column-count-3  .mo-posts-grid td.mo-post-cell {
        width: 166px;
      }

@media only screen and (max-width: 600px) {
    .mo-posts-grid td.mo-post-cell {
        display: block !important;
        width: 100% !important;
        padding: 0 0 20px 0 !important;
    }
}
CSS;

    }
}