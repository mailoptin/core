<?php

namespace MailOptin\Core\Repositories;


class EmailTemplatesRepository extends AbstractRepository
{
    private static $email_templates;

    public static function defaultTemplate()
    {
        if (is_null(self::$email_templates)) {
            self::$email_templates = apply_filters('mailoptin_registered_email_templates', array(
                // The MJML templates come first (Folio leads); Lucid is unchanged for the emails using it.
                array(
                    'name'           => 'Folio',
                    'template_class' => 'Folio',
                    'campaign_type'  => EmailCampaignRepository::NEW_PUBLISH_POST,
                    'screenshot'     => MAILOPTIN_ASSETS_URL . 'images/email-templates/folio.png'
                ),
                array(
                    'name'           => 'Folio',
                    'template_class' => 'Folio',
                    'campaign_type'  => EmailCampaignRepository::POSTS_EMAIL_DIGEST,
                    'screenshot'     => MAILOPTIN_ASSETS_URL . 'images/email-templates/folio-digest.png'
                ),
                array(
                    'name'           => 'Ledger',
                    'template_class' => 'Ledger',
                    'campaign_type'  => EmailCampaignRepository::NEW_PUBLISH_POST,
                    'screenshot'     => MAILOPTIN_ASSETS_URL . 'images/email-templates/ledger.png'
                ),
                array(
                    'name'           => 'Ledger',
                    'template_class' => 'Ledger',
                    'campaign_type'  => EmailCampaignRepository::POSTS_EMAIL_DIGEST,
                    'screenshot'     => MAILOPTIN_ASSETS_URL . 'images/email-templates/ledger-digest.png'
                ),
                array(
                    'name'           => 'Letter',
                    'template_class' => 'Letter',
                    'campaign_type'  => EmailCampaignRepository::NEW_PUBLISH_POST,
                    'screenshot'     => MAILOPTIN_ASSETS_URL . 'images/email-templates/letter.png'
                ),
                array(
                    'name'           => 'Letter',
                    'template_class' => 'Letter',
                    'campaign_type'  => EmailCampaignRepository::POSTS_EMAIL_DIGEST,
                    'screenshot'     => MAILOPTIN_ASSETS_URL . 'images/email-templates/letter-digest.png'
                ),
                array(
                    'name'           => 'Spotlight',
                    'template_class' => 'Spotlight',
                    'campaign_type'  => EmailCampaignRepository::NEW_PUBLISH_POST,
                    'screenshot'     => MAILOPTIN_ASSETS_URL . 'images/email-templates/spotlight.png'
                ),
                array(
                    'name'           => 'Spotlight',
                    'template_class' => 'Spotlight',
                    'campaign_type'  => EmailCampaignRepository::POSTS_EMAIL_DIGEST,
                    'screenshot'     => MAILOPTIN_ASSETS_URL . 'images/email-templates/spotlight-digest.png'
                ),
                array(
                    'name'           => 'Shelf',
                    'template_class' => 'Shelf',
                    'campaign_type'  => EmailCampaignRepository::NEW_PUBLISH_POST,
                    'screenshot'     => MAILOPTIN_ASSETS_URL . 'images/email-templates/shelf.png'
                ),
                array(
                    'name'           => 'Shelf',
                    'template_class' => 'Shelf',
                    'campaign_type'  => EmailCampaignRepository::POSTS_EMAIL_DIGEST,
                    'screenshot'     => MAILOPTIN_ASSETS_URL . 'images/email-templates/shelf-digest.png'
                ),
                array(
                    'name'           => 'Market',
                    'template_class' => 'Market',
                    'campaign_type'  => EmailCampaignRepository::NEW_PUBLISH_POST,
                    'screenshot'     => MAILOPTIN_ASSETS_URL . 'images/email-templates/market.png'
                ),
                array(
                    'name'           => 'Market',
                    'template_class' => 'Market',
                    'campaign_type'  => EmailCampaignRepository::POSTS_EMAIL_DIGEST,
                    'screenshot'     => MAILOPTIN_ASSETS_URL . 'images/email-templates/market-digest.png'
                ),
                array(
                    'name'           => 'Lucid',
                    'template_class' => 'Lucid',
                    'campaign_type'  => EmailCampaignRepository::NEW_PUBLISH_POST,
                    'screenshot'     => MAILOPTIN_ASSETS_URL . 'images/email-templates/lucid.png'
                ),
                array(
                    'name'           => 'Lucid',
                    'template_class' => 'Lucid',
                    'campaign_type'  => EmailCampaignRepository::POSTS_EMAIL_DIGEST,
                    'screenshot'     => MAILOPTIN_ASSETS_URL . 'images/email-templates/lucid.png'
                ),
                array(
                    'name'           => 'Lucid',
                    'template_class' => 'Lucid',
                    'campaign_type'  => EmailCampaignRepository::NEWSLETTER,
                    'screenshot'     => MAILOPTIN_ASSETS_URL . 'images/email-templates/lucid.png'
                )
            ));
        }
    }

    /**
     * All email templates available.
     *
     * @return mixed
     */
    public static function get_all()
    {
        self::defaultTemplate();

        return self::$email_templates;
    }

    /**
     * Get email_template of a given type.
     *
     * @param string $campaign_type
     *
     * @return mixed
     */
    public static function get_by_type($campaign_type)
    {
        $all = self::get_all();

        return array_reduce($all, function ($carry, $item) use ($campaign_type) {

            // remove leading & trailing whitespace.
            $campaign_type_array = array_map('trim', explode(',', $item['campaign_type']));

            if (in_array($campaign_type, $campaign_type_array)) {
                $carry[] = $item;
            }

            return $carry;
        });
    }

    /**
     * Get email_template by name.
     *
     * @param string $name
     *
     * @return mixed
     */
    public static function get_by_name($name)
    {
        $all = self::get_all();

        return array_reduce($all, function ($carry, $item) use ($name) {

            if ($item['name'] == $name) {
                $carry = $item;
            }

            return $carry;
        });
    }

    /**
     * Add email template to template repository.
     *
     * @param mixed $data
     *
     * @return void
     */
    public static function add($data)
    {
        self::defaultTemplate();
        self::$email_templates[] = $data;
    }
}