<?php

namespace MailOptin\Core\Admin\SettingsPage;


use League\Csv\Reader;
use MailOptin\Core\Repositories\OptinCampaignsRepository;
use MailOptin\Core\Repositories\OptinConversionsRepository;

class ConversionImport {

    /**
     * a call to read the csv file
     */
    public function process_upload($file) {
        $csv_mines = array('text/x-comma-separated-values', 'text/comma-separated-values', 'application/octet-stream', 'application/vnd.ms-excel', 'application/x-csv', 'text/x-csv', 'text/csv', 'application/csv', 'application/excel', 'application/vnd.msexcel', 'text/plain');

        $file_temp_name = $file['tmp_name'];
        $file_name = $file['name'];
        $file_type = $file['type'];

        $new_file_mime = mime_content_type($file_temp_name);
        $target_dir = wp_get_upload_dir();
        $new_file_path = $target_dir['path'].'/'.rand(1, 9999).'-'.$file_name;

        if(!empty($file_name) && in_array($file_type, $csv_mines)) {
            if(is_uploaded_file($file_temp_name) && move_uploaded_file($file_temp_name, $new_file_path)) {
                $upload_id = wp_insert_attachment( array(
                    'guid'           => $new_file_path,
                    'post_mime_type' => $new_file_mime,
                    'post_title'     => preg_replace( '/\.[^.]+$/', '', $file_name ),
                    'post_content'   => '',
                    'post_status'    => 'inherit'
                ), $new_file_path );

                // wp_generate_attachment_metadata() won't work if you do not include this file
                require_once( ABSPATH . 'wp-admin/includes/image.php' );

                // Generate and save the attachment metas into the database
                wp_update_attachment_metadata( $upload_id, wp_generate_attachment_metadata( $upload_id, $new_file_path ) );
            }

            // Show the uploaded file in browser
            $file_path = $target_dir['url'] . '/' . basename($new_file_path);

            //process csv
            $this->read_csv($file_path);

        }
    }


    /**
     * read the csv and process
     */
    public function read_csv($file_path) {
        $csv = Reader::createFromPath($file_path, 'r');
        $nbInsert = $csv->each(function ($row) use (&$sth) {
            echo $row[0];
        });
    }

    /**
     * @return ConversionImport|null
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