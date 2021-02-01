<?php

namespace MailOptin\Core\Admin\SettingsPage;


use League\Csv\Reader;
use MailOptin\Core\Core;
use MailOptin\Core\Repositories\OptinCampaignsRepository;
use MailOptin\Core\Repositories\OptinConversionsRepository;
use WpFluent\Exception;

class ConversionImport
{
    private $conversion_data;

    /**
     * Class constructor
     */
    public function __construct()
    {
        $this->conversion_data = 'mo_conversion_csv_path';
    }
    /**
     * a call to read the csv file
     */
    public function process_upload($file) {
        $csv_mines = array('text/x-comma-separated-values', 'text/comma-separated-values', 'application/octet-stream', 'application/vnd.ms-excel', 'application/x-csv', 'text/x-csv', 'text/csv', 'application/csv', 'application/excel', 'application/vnd.msexcel', 'text/plain');

        $file_temp_name = $file['tmp_name'];
        $file_name = $file['name'];
        $file_type = $file['type'];

        $target_dir = wp_get_upload_dir();
        $new_file_path = $target_dir['path'].'/'.rand(1, 9999).'-'.$file_name;

        if(!empty($file_name) && in_array($file_type, $csv_mines)) {
            if(is_uploaded_file($file_temp_name) && move_uploaded_file($file_temp_name, $new_file_path)) {
                //save csv
                if(update_option($this->conversion_data, $new_file_path)) {
                    wp_safe_redirect(add_query_arg('step', '2', MAILOPTIN_LEAD_IMPORT_CSV_SETTINGS_PAGE));
                    exit;
                }
            }
        }
    }

    /**
     * call to import function
     *
     */
    public function import($fields) {
        $csv_headers = $this->read_csv_headers();
        $file_path = get_option($this->conversion_data);

        $reader = Reader::createFromPath($file_path, 'r');

        $data = $reader->fetchAssoc($csv_headers);
        array_shift($data);

        $insert_data = [];
        $conversionRepoResponse = false;

        try {
            foreach ($data as $key => $value) {
                foreach ($fields as $field_key => $field_value) {
                    if (isset($value[$field_value])) {
                        $insert_data[$field_key] = esc_html($value[$field_value]);
                    }
                }

                //add fields to data before passing

                $insert_data['optin_campaign_id'] = 0; // since it's non mailoptin form, set it to zero.
                $insert_data['optin_campaign_type'] = esc_html__('Import Leads with CSV', 'mailoptin');;
                $insert_data['user_agent'] = esc_html($_SERVER['HTTP_USER_AGENT']);
                $insert_data['conversion_page'] = __('Leads Import', 'mailoptin');
                $insert_data['referrer'] = __('Leads Import', 'mailoptin');
                $insert_data['custom_fields'] = NULL;

                //insert into repository
                $conversionRepoResponse = OptinConversionsRepository::add($insert_data);
            }
        } catch(Exception $e) {

        }


        //If the conversion is true
        if($conversionRepoResponse) {
            //remove the file path
            unlink($file_path);

            //delete the path saved in wp_options table
            delete_option($this->conversion_data);
            wp_safe_redirect(add_query_arg('step', '3', MAILOPTIN_LEAD_IMPORT_CSV_SETTINGS_PAGE));
            exit;
        } else {
            wp_safe_redirect(add_query_arg('step', '2', MAILOPTIN_LEAD_IMPORT_CSV_SETTINGS_PAGE));
            exit;
        }
    }

    /**
     *  Associative array of columns
     *
     * @return array
     */
    public function get_columns()
    {
        $columns = array(
            'email'          => __('Subscriber Email', 'mailoptin'),
            'name'           => __('Subscriber Name', 'mailoptin'),
        );

        return $columns;
    }


    /**
     * read the csv and process
     */
    public function read_csv_headers() {
        $file_path = get_option('mo_conversion_csv_path');

        if(empty($file_path)) {
            wp_safe_redirect(MAILOPTIN_LEAD_IMPORT_CSV_SETTINGS_PAGE);
            exit;
        }

        $reader = Reader::createFromPath($file_path, 'r');

        //return the first row, usually the CSV header
        return $reader->fetchOne();
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