<?php

namespace MailOptin\Core\Admin\SettingsPage;

use MailOptin\Core\Repositories\OptinCampaignsRepository;

class NavigationMenu {

    public function __construct() {

        //Admin Menu Editor
        add_action( 'wp_nav_menu_item_custom_fields', [$this, 'nav_menu_fields'], 10, 4 );
        add_action( 'wp_update_nav_menu_item', [$this, 'save_menu'], 10, 2 );
        add_filter( 'manage_nav-menus_columns', [$this, 'nav_menu_columns'], 11 );
    }



    /**
     * Processes the saving of menu items.
     *
     * @param $menu_id
     * @param $item_id
     */
    public function save( $menu_id, $item_id ) {
        $lighboxes = $this->only_active_lighbox();

        if ( ! isset( $_POST['mo-nav-menu-editor-nonce'] ) || ! wp_verify_nonce( $_POST['mo-nav-menu-editor-nonce'], 'mo-nav-menu-editor-nonce' ) ) {
            return;
        }




    }

    public function only_active_lighbox() {
        $lightboxes = OptinCampaignsRepository::get_optin_campaigns_by_type('lightbox');

        $active_lightboxes  = [];
        foreach($lightboxes as $lightbox) {
            if (OptinCampaignsRepository::is_activated($lightbox->id)) {
                $active_lightboxes[] = $lightbox;
            }
        }

        return $active_lightboxes;
    }


    /**
     * Adds custom fields to the menu item editor.
     *
     * @param $item_id
     * @param $item
     * @param $depth
     * @param $args
     */
    public function nav_menu_fields( $item_id, $item, $depth, $args ) {

        wp_nonce_field( 'mo-nav-menu-editor-nonce', 'mo-nav-menu-editor-nonce' ); ?>

        <p class="mo-field-menu description description-wide">

            <label for="edit-menu-item-popup_id-<?php echo $item->ID; ?>">
                <?php esc_attr_e( 'MailOptin Trigger Lightbox', 'mailoptin' ); ?><br />

                <select name="" id="" class="widefat edit-menu-item-popup_id">
                    <option value=""><?php esc_attr_e('Select One...', 'mailoptin') ?></option>
                    <?php foreach ($this->only_active_lighbox() as $optin ) { ?>
                        <option value="<?= $optin->id ?>"><?= $optin->name; ?></option>
                    <?php } ?>
                </select>

                <span class="description"><?php esc_attr_e( 'Choose a lightbox to trigger when this menu item is clicked.', 'mailoptin' ); ?></span>
            </label>

        </p>

        <?php
    }


    public function nav_menu_columns( $columns = array() ) {
        $columns['mailoptin'] = __( 'MailOptin', 'popup-maker' );

        return $columns;
    }

    /**
     * @return NavigationMenu|null
     *
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