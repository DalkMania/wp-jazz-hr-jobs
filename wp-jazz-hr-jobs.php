<?php
/*
Plugin Name: Jazz HR Jobs Listings Plugin
Description: Jazz HR is an online software that helps companies post jobs online, manage applicants and hire great employees.
Plugin URI: http://www.niklasdahlqvist.com
Author: Niklas Dahlqvist
Author URI: http://www.niklasdahlqvist.com
Version: 1.0.0
Requires at least: 4.8.3
License: GPL
*/

/*
   Copyright 2021  Niklas Dahlqvist  (email : dalkmania@gmail.com)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

/**
* Ensure class doesn't already exist
*/
if (! class_exists("JazzHRJobs")) {
    class JazzHRJobs
    {
        private $options;
        private $apiBaseUrl;

        /**
         * Start up
         */
        public function __construct()
        {
            $this->options = get_option('jazz_hr_settings');
            $this->api_key = $this->options['api_key'];
            $this->apiBaseUrl = 'https://api.resumatorapi.com/v1/';

            add_action('admin_menu', array( $this, 'add_plugin_page' ));
            add_action('admin_init', array( $this, 'page_init' ));
            add_action('wp_enqueue_scripts', [$this, 'plugin_styles']);
            add_action('admin_enqueue_scripts', [$this, 'admin_plugin_styles']);
            add_action('wp_ajax_cache_clear', [$this, 'clearCache']);
            add_shortcode('jazz_hr_job_listings', array( $this,'JobsShortCode'));
        }

        public function plugin_admin_styles()
        {
            wp_enqueue_style('jazz_jobs-admin-styles', $this->getBaseUrl() . '/assets/css/plugin-admin-styles.css');
        }

        public function plugin_styles()
        {
            global $post;

            if (is_404()) {
                return;
            }

            $shortcode = false;
            $fields = get_post_meta($post->ID);

            if (!empty($fields)) {
                foreach ($fields as $key => $val) {
                    if (substr($key, 0, 1) !== '_') {
                        if (preg_match('/jazz_hr_job_listings/', $val[0], $match)) {
                            $shortcode = true;
                        }
                    }
                }
            }

            if ($shortcode) {
                wp_enqueue_style('jazz-jobs-styles', $this->getBaseUrl() . '/assets/css/jazz-postings-styles.css');
                wp_enqueue_script('jazz-jobs-script', $this->getBaseUrl() . '/assets/js/jazz-jobs-filter.js', '1.0.0', true);
            }

            if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'jazz_hr_job_listings')) {
                wp_enqueue_style('jazz-jobs-styles', $this->getBaseUrl() . '/assets/css/jazz-postings-styles.css');
                wp_enqueue_script('jazz-jobs-script', $this->getBaseUrl() . '/assets/js/jazz-jobs-filter.js', '1.0.0', true);
            }
        }

        public function admin_plugin_styles()
        {
            wp_enqueue_style('jazz-jobs-admin-styles', $this->getBaseUrl() . '/assets/css/jazz-postings-admin-styles.css', '1.0.0', true);
            wp_enqueue_script('jazz-admin', $this->getBaseUrl() . '/assets/js/jazz-admin.js', ['jquery'], '1.0.0', true);
        }

        /**
         * Add options page
         */
        public function add_plugin_page()
        {
            // This page will be under "Settings"
            add_management_page(
                'Jazz HR Settings Admin',
                'Jazz HR Settings',
                'manage_options',
                'jazz_hr-settings-admin',
                array( $this, 'create_admin_page' )
            );
        }

        /**
         * Options page callback
         */
        public function create_admin_page()
        {
            // Set class property
        $this->options = get_option('jazz_hr_settings'); ?>
        <div class="wrap jazz_jobs-settings">
          <h2>Jazz HR Settings</h2>
          <form method="post" action="options.php">
          <?php
              // This prints out all hidden setting fields
              settings_fields('jazz_hr_settings_group');
            do_settings_sections('jazz_hr-settings-admin');
            submit_button();
            submit_button('Clear Cache', 'delete', 'clear_cache', false); ?>
          </form>
        </div>
        <?php
        }

        /**
         * Register and add settings
         */
        public function page_init()
        {
            register_setting(
                'jazz_hr_settings_group', // Option group
          'jazz_hr_settings', // Option name
          array( $this, 'sanitize' ) // Sanitize
            );

            add_settings_section(
                'jazz_hr_section', // ID
          'Jazz HR Settings', // Title
          array( $this, 'print_section_info' ), // Callback
          'jazz_hr-settings-admin' // Page
            );

            add_settings_field(
                'api_key', // ID
          'Jazz HR API Key', // Title
          array( $this, 'jazz_hr_api_key_callback' ), // Callback
          'jazz_hr-settings-admin', // Page
          'jazz_hr_section' // Section
            );
        }

        /**
         * Sanitize each setting field as needed
         *
         * @param array $input Contains all settings fields as array keys
         */
        public function sanitize($input)
        {
            $new_input = array();
            if (isset($input['api_key'])) {
                $new_input['api_key'] = sanitize_text_field($input['api_key']);
            }

            return $new_input;
        }

        /**
         * Print the Section text
         */
        public function print_section_info()
        {
            echo '<p>Enter your settings below:';
            echo '<br />and then use the <strong>[jazz_hr_job_listings]</strong> shortcode to display the content.</p>';
        }

        /**
         * Get the settings option array and print one of its values
         */
        public function jazz_hr_api_key_callback()
        {
            printf(
                '<input type="text" id="api_key" class="narrow-fat" name="jazz_hr_settings[api_key]" value="%s" />',
                isset($this->options['api_key']) ? esc_attr($this->options['api_key']) : ''
            );
        }

        public function JobsShortCode($atts, $content = null)
        {
            global $post;
            if (isset($this->api_key) && $this->api_key != '') {
                $output = '';
                $positions = $this->get_jazz_positions();

                $output .= "
                    <div class='job-filters'>
                      {$this->generateFilterDropdowns()}
                    </div>
                    <div class='filter-results'>
                      <div class='no-results-message hidden'>
                        <p class='sub-title'>There are currently no jobs matching your criteria.</p>
                      </div>";

                $output .= '<ul class="job-listings">';

                foreach ($positions as $position) {
                    $output .= "<li class='job-listing' data-posting-id='{$position['id']}' data-filter-date='{$position['createdAt']}' data-filter-location='{$position['location']}' data-filter-department='{$position['department']}' data-filter-commitment='{$position['commitment']}' data-featured='0' data-show='true'>
                            <div class='posting'>
                                <h4><a href='{$position['applyUrl']}' target='_blank'>{$position['title']}</a></h4>
                                <div class='posting-categories'>
                                    <div href='#' class='sort-by-location posting-category'><em>Location:</em> {$position['location']}</div>
                                    <div href='#' class='sort-by-commitment posting-category'><em>Job Type:</em> {$position['commitment']}</div>
                                </div>
                            </div> 
                            <div class='posting-apply'>
                                <a class='apply-button' href='{$position['applyUrl']}' target='_blank'>Apply</a>
                            </div>
                          </li>
                          ";
                }
                $output .= '</ul>';
                $output .= '</div>';
                $output .= '</div>';

                $output_wrapped = "<div class='jazz_jobs_wrapper'>
                                    <div class='output'>
                                        {$output}
                                    </div>
                                  </div>";
                return $output_wrapped;
            }
        }

        public function generateFilterDropdowns()
        {
            // Location Filter
            $locations = $this->get_jazz_locations();
            $location_options = "<option value=''> - Location - </option>";
            foreach ($locations as $location) {
                $location_options .= "<option value='{$location}'>$location</option>";
            }
            $location_output = "
            <select class='form-control filter' data-filter='location'>
              {$location_options}
            </select>
          ";

            // Teams
            $teams = $this->get_jazz_teams();
            $team_options = "<option value=''> - Team - </option>";
            foreach ($teams as $team) {
                $team_options .= "<option value='{$team}'>$team</option>";
            }
            $team_output = "
            <select class='form-control filter' data-filter='team'>
              {$team_options}
            </select>
          ";

            // Departments
            $depts = $this->get_jazz_departments();
            $dept_options = "<option value=''> - Department - </option>";
            foreach ($depts as $dept) {
                $dept_options .= "<option value='{$dept}'>$dept</option>";
            }
            $dept_output = "
            <select class='form-control filter' data-filter='department'>
              {$dept_options}
            </select>
          ";

            // Job Type / Commitment
            $commitments = $this->get_jazz_commitments();
            $commitment_options = "<option value=''> - Job Type - </option>";
            foreach ($commitments as $commitment) {
                $commitment_options .= "<option value='{$commitment}'>$commitment</option>";
            }
            $commitment_output = "
            <select class='form-control filter' data-filter='commitment'>
              {$commitment_options}
            </select>
          ";

            return "<div class='filter-row'>
              <div class='col-4'>
                {$location_output} 
              </div>
              <div class='col-4'>
                {$dept_output}
              </div>
              <div class='col-4'>
                {$commitment_output}
              </div>
            </div>";
        }

        // Send Curl Request to Lever Endpoint and return the response
        public function sendRequest()
        {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->apiBaseUrl . 'jobs/status/open?apikey=' .$this->api_key);
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            $response = json_decode(curl_exec($ch));
            return $response;
        }

        public function get_jazz_positions()
        {
            // Get any existing copy of our transient data
            if (false === ($jobs = get_transient('jazz_positions'))) {
                // It wasn't there, so make a new API Request and regenerate the data
                $positions = $this->sendRequest();
                $jobs = [];

                if ($positions != '') {
                    if (is_array($positions)) {
                        foreach ($positions as $item) {
                            $jazz_position = [
                            'id' => $item->id,
                            'title' => $item->title,
                            'location' => $item->city . ', ' . $item->state,
                            'commitment' => $item->type,
                            'department' => $item->department,
                            'team' => $item->team_id,
                            'description' => preg_replace('#(<[a-z ]*)(style=("|\')(.*?)("|\'))([a-z ]*>)#', '\\1\\6', $item->description),
                            'applyUrl' => 'https://madmobileinc.applytojob.com/apply/jobs/details/' . $item->board_code,
                            'createdAt' => strtotime($item->original_open_date)
                        ];

                            array_push($jobs, $jazz_position);
                        }
                    } else {
                        $jazz_position = [
                        'id' => $positions->id,
                        'title' => $positions->title,
                        'location' => $positions->city . ', ' . $positions->state,
                        'commitment' => $positions->type,
                        'department' => $positions->department,
                        'team' => $positions->team_id,
                        'description' => preg_replace('#(<[a-z ]*)(style=("|\')(.*?)("|\'))([a-z ]*>)#', '\\1\\6', $positions->description),
                        'applyUrl' => 'https://madmobileinc.applytojob.com/apply/jobs/details/' . $positions->board_code,
                        'createdAt' => strtotime($positions->original_open_date)
                    ];

                        array_push($jobs, $jazz_position);
                    }

                    // Cache the Response
                    $this->storeJazzPostions($jobs);
                }
            } else {
                // Get any existing copy of our transient data
                $jobs = unserialize(get_transient('jazz_positions'));
            }
            // Finally return the data
            return $jobs;
        }

        public function get_jazz_locations()
        {
            $locations = array();
            $positions = $this->get_jazz_positions();

            foreach ($positions as $position) {
                $locations[]  = $position['location'];
            }

            $locations = array_unique($locations);
            sort($locations);

            return $locations;
        }

        public function get_jazz_commitments()
        {
            $commitments = array();
            $positions = $this->get_jazz_positions();

            foreach ($positions as $position) {
                $commitments[]  = $position['commitment'];
            }

            $commitments = array_unique($commitments);
            sort($commitments);

            return $commitments;
        }

        public function get_jazz_teams()
        {
            $teams = array();
            $positions = $this->get_jazz_positions();

            foreach ($positions as $position) {
                $teams[]  = $position['team'];
            }

            $teams = array_unique($teams);
            sort($teams);

            return $teams;
        }

        public function get_jazz_departments()
        {
            $depts = [];
            $positions = $this->get_jazz_positions();

            foreach ($positions as $position) {
                $depts[] = $position['department'];
            }

            $depts = array_unique($depts);
            sort($depts);

            return $depts;
        }

        public function storeJazzPostions($positions)
        {
            // Get any existing copy of our transient data
            if (false === ($jazz_data = get_transient('jazz_positions'))) {
                // It wasn't there, so regenerate the data and save the transient for 12 hours
                $jazz_data = serialize($positions);
                set_transient('jazz_positions', $jazz_data, 12 * HOUR_IN_SECONDS);
            }
        }

        public function flushStoredInformation()
        {
            //Delete transient to force a new pull from the API
            delete_transient('jazz_positions');
        }

        public function clearCache()
        {
            if (isset($_POST['action']) && $_POST['action'] === 'cache_clear') {
                $this->flushStoredInformation();
                $output = ['cache_cleared' => true, 'message' => 'The Transients for the Job Listings have been cleared'];
                echo json_encode($output);
                exit;
            }
        }

        //Returns the url of the plugin's root folder
        protected function getBaseUrl()
        {
            return plugins_url(null, __FILE__);
        }

        //Returns the physical path of the plugin's root folder
        protected function getBasePath()
        {
            $folder = basename(dirname(__FILE__));
            return WP_PLUGIN_DIR . "/" . $folder;
        }
    } //End Class

    /**
     * Instantiate this class to ensure the action and shortcode hooks are hooked.
     * This instantiation can only be done once (see it's __construct() to understand why.)
     */
    new JazzHRJobs();
} // End if class exists statement
