<?php

namespace MailOptin\Core\Admin\SettingsPage;

use MailOptin\Core\Repositories\OptinCampaignsRepository;

class NavigationMenu {

    public function __construct() {

        // Merge Menu Item Options
        add_filter( 'wp_setup_nav_menu_item', [$this, 'merge_item_data']);

        //Admin Menu Editor
        add_action( 'wp_nav_menu_item_custom_fields', [$this, 'nav_menu_fields'], 10, 4 );
        add_action( 'wp_update_nav_menu_item', [$this, 'save_menu'], 10, 2 );
        add_filter( 'manage_nav-menus_columns', [$this, 'nav_menu_columns'], 11 );
    }

    /**
     * @param array $options
     *
     * @return array
     */
    public function parse_item_options( $options = array() ) {

        if ( ! is_array( $options ) ) {
            $options = array();
        }

        return wp_parse_args( $options, array(
            'lightbox_id' => null,
        ) );
    }

    /**
     * Processes the saving of menu items.
     *
     * @param $menu_id
     * @param $item_id
     */
    public function save_menu( $menu_id, $item_id ) {
        //only active lightbox
        $lighboxes = $this->only_active_lighbox();

        $allowed_lighboxes = wp_parse_id_list( array_keys( $lighboxes ) );

        if ( ! isset( $_POST['mo-nav-menu-editor-nonce'] ) || ! wp_verify_nonce( $_POST['mo-nav-menu-editor-nonce'], 'mo-nav-menu-editor-nonce' ) ) {
            return;
        }

        /**
         * Return early if there are no settings.
         */
        if ( empty( $_POST['menu-trigger-item-mo'][ $item_id ] ) ) {
            delete_post_meta( $item_id, '_mo_menu_trigger_item_options' );
            return;
        }

        /**
         * Parse options array for valid keys.
         */
        $item_options = $this->parse_item_options( $_POST['menu-trigger-item-mo'][ $item_id ] );

        /**
         * Check for invalid values.
         */
        if (!in_array($item_options['lightbox_id'], $allowed_lighboxes) || $item_options['lightbox_id'] <= 0 ) {
            unset( $item_options['lightbox_id'] );
        }

        /**
         * Remove empty options to save space.
         */
        $item_options = array_filter( $item_options );

        /**
         * Save options or delete if empty.
         */
        if ( ! empty( $item_options ) ) {
            update_post_meta( $item_id, '_mo_menu_trigger_item_options', $item_options );
        } else {
            delete_post_meta( $item_id, '_mo_menu_trigger_item_options' );
        }

    }

    public function only_active_lighbox() {
        $lightboxes = OptinCampaignsRepository::get_optin_campaigns_by_type('lightbox');

        $active_lightboxes  = [];
        foreach($lightboxes as $lightbox) {
            if (OptinCampaignsRepository::is_activated($lightbox->id)) {
                $active_lightboxes[$lightbox->id] = [
                        'id'                    => $lightbox->id,
                        'name'                  => $lightbox->name,
                        'uuid'                  => $lightbox->uuid,
                        'optin_class'            => $lightbox->optin_class,
                        'optin_type'            => $lightbox->optin_type
                ];
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

            <label for="edit-mo-trigger-menu-item-lightbox_id-<?php echo $item->ID; ?>">
                <?php esc_attr_e( 'MailOptin Trigger Lightbox', 'mailoptin' ); ?><br />

                <select name="menu-trigger-item-mo[<?php echo $item->ID; ?>][lightbox_id]" id="edit-mo-trigger-menu-item-lightbox_id-<?php echo $item->ID; ?>" class="widefat edit-menu-item-popup_id">
                    <option value=""><?php esc_attr_e('Select One...', 'mailoptin') ?></option>
                    <?php foreach ($this->only_active_lighbox() as $optin ) { ?>
                        <option value="<?= $optin['id'] ?>" <?php selected( $optin['id'], $item->lightbox_id ); ?>><?= $optin['name']; ?></option>
                    <?php } ?>
                </select>

                <span class="description"><?php esc_attr_e( 'Choose a lightbox to trigger when this menu item is clicked.', 'mailoptin' ); ?></span>
            </label>

        </p>

        <?php
    }

    /**
     * Merge Item data into the $item object.
     *
     * @param $item
     *
     * @return mixed
     */
    public function merge_item_data( $item ) {
        if ( ! is_object( $item ) || ! isset( $item->ID ) || $item->ID <= 0 ) {
            return $item;
        }

        // Merge Rules.
        foreach ( $this->get_item_options( $item->ID ) as $key => $value ) {
            $item->$key = $value;
        }

        if ( is_admin() ) {
            return $item;
        }

        if ( isset( $item->popup_id ) ) {
            $item->classes[] = 'mo-lightbox-' . $item->lightbox_id;
        }

        foreach ( $item->classes as $class ) {
            if ( strpos( $class, 'mo-lightbox-' ) !== false ) {
                if ( 0 !== preg_match( '/mo-lightbox-(\d+)/', $class, $matches ) ) {
                  //preload function

                }
            }
        }

        return $item;
    }


    /**
     * @param int $item_id
     *
     * @return array
     */
    public function get_item_options( $item_id = 0 ) {

        // Fetch all rules for this menu item.
        $item_options = get_post_meta( $item_id, '_mo_menu_trigger_item_options', true );

        return $this->parse_item_options( $item_options );
    }


    public function nav_menu_columns( $columns = array() ) {
        $columns['lightbox_id'] = __( 'MailOptin', 'mailoptin' );

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