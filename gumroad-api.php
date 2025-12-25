<?php  
/** 
 * Plugin Name: Gumroad API WordPress
 * Plugin URI: https://github.com/sinanisler/gumroad-api-wordpress
 * Description: Connect your WordPress site with Gumroad API to automatically create user accounts when customers make a purchase. Uses scheduled API polling to monitor sales.
 * Version: 0.5
 * Author: sinanisler
 * Author URI: https://sinanisler.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: snn
 */

if (!defined('ABSPATH')) {
    exit;
}

// Include GitHub auto-update functionality
require_once plugin_dir_path(__FILE__) . 'github-update.php';

class Gumroad_API_WordPress {
    
    private $option_name = 'gumroad_api_settings';
    private $log_option_name = 'gumroad_api_logs';
    
    public function __construct() {
        // Admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        
        // Cron job for API-based sales fetching
        add_action('gumroad_api_check_sales', array($this, 'check_recent_sales'));
        add_filter('cron_schedules', array($this, 'add_custom_cron_interval'));
        
        // Activation/Deactivation
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // AJAX handlers
        add_action('wp_ajax_gumroad_test_api', array($this, 'test_api_connection'));
        add_action('wp_ajax_gumroad_clear_logs', array($this, 'clear_logs'));
        add_action('wp_ajax_gumroad_fetch_products', array($this, 'fetch_products'));
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Set default options
        $default_options = array(
            'access_token' => '',
            'auto_create_users' => false,
            'default_roles' => array('subscriber'),
            'product_roles' => array(),
            'products' => array(),
            'cron_interval' => 120,
            'sales_limit' => 50,
            'send_welcome_email' => true,
            'email_subject' => 'Welcome to {{site_name}}!',
            'email_template' => $this->get_default_email_template(),
            'log_limit' => 500,
            'user_list_per_page' => 20
        );
        
        if (!get_option($this->option_name)) {
            add_option($this->option_name, $default_options);
        }
        
        // Schedule cron
        $this->schedule_cron();
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        $timestamp = wp_next_scheduled('gumroad_api_check_sales');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'gumroad_api_check_sales');
        }
    }
    
    /**
     * Add custom cron interval
     */
    public function add_custom_cron_interval($schedules) {
        $settings = get_option($this->option_name);
        $interval = isset($settings['cron_interval']) ? intval($settings['cron_interval']) : 120;
        
        $schedules['gumroad_custom'] = array(
            'interval' => $interval,
            'display'  => sprintf(__('Every %d seconds', 'snn'), $interval)
        );
        
        return $schedules;
    }
    
    /**
     * Schedule cron job
     */
    private function schedule_cron() {
        $timestamp = wp_next_scheduled('gumroad_api_check_sales');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'gumroad_api_check_sales');
        }
        
        wp_schedule_event(time(), 'gumroad_custom', 'gumroad_api_check_sales');
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            __('Gumroad API', 'snn'),
            __('Gumroad API', 'snn'),
            'manage_options',
            'gumroad-api',
            array($this, 'settings_page'),
            'dashicons-cart',
            120
        );
        
        add_submenu_page(
            'gumroad-api',
            __('Settings', 'snn'),
            __('Settings', 'snn'),
            'manage_options',
            'gumroad-api',
            array($this, 'settings_page')
        );
        
        add_submenu_page(
            'gumroad-api',
            __('API Logs', 'snn'),
            __('API Logs', 'snn'),
            'manage_options',
            'gumroad-api-logs',
            array($this, 'logs_page')
        );
        
        add_submenu_page(
            'gumroad-api',
            __('User List', 'snn'),
            __('User List', 'snn'),
            'manage_options',
            'gumroad-api-users',
            array($this, 'users_page')
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('gumroad_api_settings_group', $this->option_name);
    }
    
    /**
     * Check recent sales via API (cron job)
     */
    public function check_recent_sales() {
        $settings = get_option($this->option_name);
        $access_token = isset($settings['access_token']) ? $settings['access_token'] : '';
        
        if (empty($access_token)) {
            $this->log_activity('Cron error', array('error' => 'Access token not set'));
            return;
        }
        
        $sales_limit = isset($settings['sales_limit']) ? intval($settings['sales_limit']) : 50;
        
        // Fetch recent sales
        $response = wp_remote_get('https://api.gumroad.com/v2/sales?access_token=' . $access_token);
        
        if (is_wp_error($response)) {
            $this->log_activity('Cron API error', array('error' => $response->get_error_message()));
            return;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!isset($data['success']) || !$data['success']) {
            $this->log_activity('Cron API error', array('error' => 'API returned unsuccessful response', 'response' => $data));
            return;
        }
        
        if (isset($data['sales']) && is_array($data['sales'])) {
            $processed_sales = get_option('gumroad_processed_sales', array());
            $new_sales_count = 0;
            
            foreach (array_slice($data['sales'], 0, $sales_limit) as $sale) {
                $sale_id = isset($sale['id']) ? $sale['id'] : '';
                $email = isset($sale['email']) ? sanitize_email($sale['email']) : '';
                
                // Check if sale was processed AND user still exists
                $should_process = true;
                if (in_array($sale_id, $processed_sales)) {
                    // Sale was processed before, but check if user still exists
                    if (!empty($email)) {
                        $user = get_user_by('email', $email);
                        if ($user && get_user_meta($user->ID, 'gumroad_sale_id', true) === $sale_id) {
                            // User exists and matches this sale, skip processing
                            $should_process = false;
                        } else {
                            // User was deleted or doesn't match, remove from processed and re-process
                            $processed_sales = array_diff($processed_sales, array($sale_id));
                            $this->log_activity('Sale re-processed', array(
                                'reason' => 'User no longer exists',
                                'sale_id' => $sale_id,
                                'email' => $email
                            ));
                        }
                    }
                }
                
                if ($should_process) {
                    $result = $this->process_sale($sale);
                    
                    if (!is_wp_error($result)) {
                        $processed_sales[] = $sale_id;
                        $new_sales_count++;
                    }
                }
            }
            
            // Keep only last 1000 processed sale IDs to prevent array from growing too large
            if (count($processed_sales) > 1000) {
                $processed_sales = array_slice($processed_sales, -1000);
            }
            
            update_option('gumroad_processed_sales', $processed_sales);
            
            $this->log_activity('Cron completed', array(
                'total_sales_checked' => count($data['sales']),
                'new_sales_processed' => $new_sales_count
            ));
        }
    }
    
    /**
     * Process a sale and create/update user
     */
    private function process_sale($sale_data) {
        $email = isset($sale_data['email']) ? sanitize_email($sale_data['email']) : '';
        $product_name = isset($sale_data['product_name']) ? sanitize_text_field($sale_data['product_name']) : '';
        $product_id = isset($sale_data['product_id']) ? sanitize_text_field($sale_data['product_id']) : '';
        
        if (empty($email)) {
            return new WP_Error('invalid_email', 'Email address is required');
        }
        
        $settings = get_option($this->option_name);
        $auto_create_users = isset($settings['auto_create_users']) ? $settings['auto_create_users'] : false;
        
        // Check if auto user creation is disabled
        if (!$auto_create_users) {
            $this->log_activity('User creation skipped', array(
                'reason' => 'Auto create users is disabled',
                'email' => $email,
                'product' => $product_name
            ));
            return new WP_Error('auto_create_disabled', 'Automatic user creation is disabled');
        }
        
        // Check if user exists
        $user = get_user_by('email', $email);
        
        $default_roles = isset($settings['default_roles']) ? $settings['default_roles'] : array('subscriber');
        $product_roles = isset($settings['product_roles']) ? $settings['product_roles'] : array();
        
        // Determine roles for this product
        $roles = array();
        if (!empty($product_id) && isset($product_roles[$product_id]) && !empty($product_roles[$product_id])) {
            $roles = $product_roles[$product_id];
        } else {
            $roles = $default_roles;
        }
        
        // If no roles configured, skip user creation
        if (empty($roles)) {
            $this->log_activity('User creation skipped', array(
                'reason' => 'No roles configured for this product',
                'email' => $email,
                'product' => $product_name,
                'product_id' => $product_id
            ));
            return new WP_Error('no_roles_configured', 'No roles configured for this product');
        }
        
        if (!$user) {
            // Create new user
            $username = $this->generate_username($email);
            $password = wp_generate_password(12, true, true);
            
            $user_id = wp_create_user($username, $password, $email);
            
            if (is_wp_error($user_id)) {
                return $user_id;
            }
            
            $user = get_user_by('id', $user_id);
            
            // Assign roles
            $user->set_role($roles[0]); // Set primary role
            for ($i = 1; $i < count($roles); $i++) {
                $user->add_role($roles[$i]); // Add additional roles
            }
            
            // Store Gumroad metadata
            $sale_id = isset($sale_data['id']) ? $sale_data['id'] : '';
            update_user_meta($user_id, 'gumroad_sale_id', $sale_id);
            update_user_meta($user_id, 'gumroad_product_name', $product_name);
            update_user_meta($user_id, 'gumroad_product_id', $product_id);
            update_user_meta($user_id, 'gumroad_created_date', current_time('mysql'));
            update_user_meta($user_id, 'gumroad_sale_data', json_encode($sale_data));
            update_user_meta($user_id, 'gumroad_assigned_roles', json_encode($roles));
            
            // Send welcome email
            $email_sent = false;
            if (isset($settings['send_welcome_email']) && $settings['send_welcome_email']) {
                $email_sent = $this->send_welcome_email($user, $password, $product_name);
            }
            
            update_user_meta($user_id, 'gumroad_email_sent', $email_sent ? 'yes' : 'no');
            update_user_meta($user_id, 'gumroad_email_sent_date', $email_sent ? current_time('mysql') : '');
            
            $this->log_activity('User created', array(
                'user_id' => $user_id,
                'email' => $email,
                'product' => $product_name,
                'product_id' => $product_id,
                'sale_id' => $sale_id,
                'roles' => $roles,
                'email_sent' => $email_sent
            ));
            
            return $user_id;
        } else {
            // Update existing user roles if needed
            $roles_added = array();
            foreach ($roles as $role) {
                if (!in_array($role, (array) $user->roles)) {
                    $user->add_role($role);
                    $roles_added[] = $role;
                }
            }
            
            if (!empty($roles_added)) {
                // Update Gumroad metadata for existing user
                $sale_id = isset($sale_data['id']) ? $sale_data['id'] : '';
                update_user_meta($user->ID, 'gumroad_last_purchase_date', current_time('mysql'));
                update_user_meta($user->ID, 'gumroad_last_product_name', $product_name);
                update_user_meta($user->ID, 'gumroad_last_product_id', $product_id);
                update_user_meta($user->ID, 'gumroad_last_sale_id', $sale_id);
                
                // Append to purchase history
                $purchase_history = get_user_meta($user->ID, 'gumroad_purchase_history', true);
                if (!$purchase_history) {
                    $purchase_history = array();
                } else {
                    $purchase_history = json_decode($purchase_history, true);
                }
                $purchase_history[] = array(
                    'date' => current_time('mysql'),
                    'product_name' => $product_name,
                    'product_id' => $product_id,
                    'sale_id' => $sale_id,
                    'roles_added' => $roles_added
                );
                update_user_meta($user->ID, 'gumroad_purchase_history', json_encode($purchase_history));
                
                $this->log_activity('User roles updated', array(
                    'user_id' => $user->ID,
                    'email' => $email,
                    'product' => $product_name,
                    'product_id' => $product_id,
                    'sale_id' => $sale_id,
                    'roles_added' => $roles_added
                ));
            }
            
            return $user->ID;
        }
    }
    
    /**
     * Generate unique username from email
     */
    private function generate_username($email) {
        $username = sanitize_user(substr($email, 0, strpos($email, '@')));
        
        if (username_exists($username)) {
            $i = 1;
            while (username_exists($username . $i)) {
                $i++;
            }
            $username = $username . $i;
        }
        
        return $username;
    }
    
    /**
     * Send welcome email
     */
    private function send_welcome_email($user, $password, $product_name) {
        $settings = get_option($this->option_name);
        $email_template = isset($settings['email_template']) ? $settings['email_template'] : $this->get_default_email_template();
        $email_subject = isset($settings['email_subject']) ? $settings['email_subject'] : 'Welcome to {{site_name}}!';
        
        $reset_key = get_password_reset_key($user);
        $password_reset_url = network_site_url("wp-login.php?action=rp&key=$reset_key&login=" . rawurlencode($user->user_login), 'login');
        
        // Dynamic tags
        $tags = array(
            '{{site_name}}' => get_bloginfo('name'),
            '{{site_url}}' => get_site_url(),
            '{{product_name}}' => $product_name,
            '{{username}}' => $user->user_login,
            '{{password}}' => $password,
            '{{email}}' => $user->user_email,
            '{{login_url}}' => wp_login_url(),
            '{{password_reset_url}}' => $password_reset_url
        );
        
        $subject = str_replace(array_keys($tags), array_values($tags), $email_subject);
        $message = str_replace(array_keys($tags), array_values($tags), $email_template);
        
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        return wp_mail($user->user_email, $subject, $message, $headers);
    }
    
    /**
     * Get default email template
     */
    private function get_default_email_template() {
        return '<h2>Welcome to {{site_name}}!</h2>

<p>Hi there!</p>

<p>Thank you for purchasing <strong>{{product_name}}</strong>! Your account has been created automatically.</p>

<h3>Your Login Credentials:</h3>

<p><strong>Username:</strong> {{username}}<br>
<strong>Password:</strong> {{password}}<br>
<strong>Email:</strong> {{email}}</p>

<p><a href="{{login_url}}">Login to Your Account</a></p>

<p>If you prefer to reset your password, use this link:<br>
<a href="{{password_reset_url}}">Reset Password</a></p>

<p><strong>Important:</strong> Please keep this email safe as it contains your login credentials.</p>

<br>
<p>{{site_name}} - <a href="{{site_url}}">{{site_url}}</a></p>';
    }
    
    /**
     * Log activity
     */
    private function log_activity($type, $data) {
        $logs = get_option($this->log_option_name, array());
        $settings = get_option($this->option_name);
        $log_limit = isset($settings['log_limit']) ? intval($settings['log_limit']) : 500;
        
        $log_entry = array(
            'timestamp' => current_time('mysql'),
            'type' => $type,
            'data' => $data
        );
        
        array_unshift($logs, $log_entry);
        
        // Limit log size
        if (count($logs) > $log_limit) {
            $logs = array_slice($logs, 0, $log_limit);
        }
        
        update_option($this->log_option_name, $logs);
    }
    
    /**
     * Settings page
     */
    public function settings_page() {
        if (isset($_POST['gumroad_settings_nonce']) && wp_verify_nonce($_POST['gumroad_settings_nonce'], 'gumroad_save_settings')) {
            $this->save_settings($_POST);
            echo '<div class="notice notice-success"><p>' . __('Settings saved successfully!', 'snn') . '</p></div>';
        }
        
        $settings = get_option($this->option_name);
        $auto_create_users = isset($settings['auto_create_users']) ? $settings['auto_create_users'] : false;
        $default_roles = isset($settings['default_roles']) ? $settings['default_roles'] : array('subscriber');
        $product_roles = isset($settings['product_roles']) ? $settings['product_roles'] : array();
        
        ?>
        <div class="wrap">
            <h1><?php _e('Gumroad API Settings', 'snn'); ?></h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('gumroad_save_settings', 'gumroad_settings_nonce'); ?>
                
                <!-- API Connection Section -->
                <div class="gumroad-section">
                    <h2><?php _e('API Connection', 'snn'); ?></h2>
                    <p class="description" style="background: #e7f5ff; padding: 15px; border-left: 4px solid #2271b1;">
                        <strong>ℹ️ <?php _e('API-Based Sales Monitoring', 'snn'); ?></strong><br>
                        <?php _e('This plugin uses Gumroad API to automatically check for new sales. Configure the check interval in the "Cron Settings" tab.', 'snn'); ?>
                    </p>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="access_token"><?php _e('Gumroad Access Token', 'snn'); ?></label></th>
                            <td>
                                <input type="password" name="access_token" id="access_token" value="<?php echo esc_attr($settings['access_token']); ?>" class="regular-text" />
                                <button type="button" class="button" onclick="togglePassword('access_token')"><?php _e('Show/Hide', 'snn'); ?></button>
                                <button type="button" class="button button-primary" onclick="testApiConnection()"><?php _e('Test & Fetch Products', 'snn'); ?></button>
                                <p class="description">
                                    <?php _e('1. Go to your Gumroad Settings → Applications<br>2. Create a new application (or use existing)<br>3. Click "Generate access token"<br>4. Paste the token here', 'snn'); ?>
                                </p>
                                <div id="api-test-result"></div>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- User Management Section -->
                <div class="gumroad-section">
                    <h2><?php _e('User Management', 'snn'); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php _e('Auto Create Users', 'snn'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="auto_create_users" value="1" <?php checked($auto_create_users, 1); ?> />
                                    <strong><?php _e('Automatically create WordPress users for Gumroad purchases', 'snn'); ?></strong>
                                </label>
                                <p class="description"><?php _e('When enabled, a new WordPress user will be created for each purchase.', 'snn'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Assign User Roles', 'snn'); ?></th>
                            <td>
                                <p class="description" style="margin-top: 0;"><?php _e('Select which role(s) to assign to newly created users. You can select multiple roles.', 'snn'); ?></p>
                                <?php
                                global $wp_roles;
                                $all_roles = $wp_roles->roles;
                                foreach ($all_roles as $role_key => $role_info) {
                                    $checked = in_array($role_key, $default_roles) ? 'checked' : '';
                                    echo '<label style="display: block; margin: 5px 0;">';
                                    echo '<input type="checkbox" name="default_roles[]" value="' . esc_attr($role_key) . '" ' . $checked . ' /> ';
                                    echo esc_html($role_info['name']);
                                    echo '</label>';
                                }
                                ?>
                                <p class="description"><?php _e('Use role management plugins to create custom roles if needed.', 'snn'); ?></p>
                            </td>
                        </tr>
                    </table>
                    
                    <hr style="margin: 30px 0;">
                    
                    <h3><?php _e('Product-Specific Role Assignment', 'snn'); ?></h3>
                    <p><?php _e('Configure which roles should be assigned for each product purchase. If no roles are selected for a product, the default "Assign User Roles" setting above will be used.', 'snn'); ?></p>
                    
                    <div id="products-notice" style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0;">
                        <p><strong><?php _e('⚠️ Please test your API connection first to load your products.', 'snn'); ?></strong></p>
                        <p><?php _e('Go to the "Connection" tab and click "Test & Fetch Products" button.', 'snn'); ?></p>
                    </div>
                    
                    <div id="products-loading" style="display: none; text-align: center; padding: 20px;">
                        <span class="spinner is-active"></span>
                        <p><?php _e('Loading products...', 'snn'); ?></p>
                    </div>
                    
                    <div id="products-list" style="display: none;">
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th style="width: 40%;"><?php _e('Product Name', 'snn'); ?></th>
                                    <th style="width: 20%;"><?php _e('Product ID', 'snn'); ?></th>
                                    <th style="width: 15%;"><?php _e('Status', 'snn'); ?></th>
                                    <th style="width: 25%;"><?php _e('Select roles to assign for this product', 'snn'); ?></th>
                                </tr>
                            </thead>
                            <tbody id="products-tbody">
                                <!-- Products will be loaded here via AJAX -->
                            </tbody>
                        </table>
                        <p class="description" style="margin-top: 15px;">
                            <strong><?php _e('💡 How it works:', 'snn'); ?></strong><br>
                            • <?php _e('Configure specific roles for each product, or leave unchecked to use default roles (backward compatible)', 'snn'); ?><br>
                            • <?php _e('Only products with assigned roles will trigger user creation', 'snn'); ?><br>
                            • <?php _e('If NO products have roles assigned, ALL purchases will create users with default roles (backward compatible)', 'snn'); ?>
                        </p>
                    </div>
                </div>
                
                <!-- Welcome Email Section -->
                <div class="gumroad-section">
                    <h2><?php _e('Welcome Email Settings', 'snn'); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php _e('Send Welcome Email', 'snn'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="send_welcome_email" value="1" <?php checked($settings['send_welcome_email'], 1); ?> />
                                    <?php _e('Send welcome email to new users', 'snn'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="email_subject"><?php _e('Email Subject', 'snn'); ?></label></th>
                            <td>
                                <input type="text" name="email_subject" id="email_subject" value="<?php echo esc_attr($settings['email_subject']); ?>" class="large-text" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="email_template"><?php _e('Email Template (HTML)', 'snn'); ?></label></th>
                            <td>
                                <textarea name="email_template" id="email_template" rows="20" class="large-text code"><?php echo esc_textarea($settings['email_template']); ?></textarea>
                                <div style="margin-top: 15px; padding: 15px; background: #f0f0f1; border-left: 4px solid #2271b1;">
                                    <h4 style="margin-top: 0;">📧 <?php _e('Available Dynamic Tags:', 'snn'); ?></h4>
                                    <ul style="margin: 10px 0; padding-left: 20px;">
                                        <li><code>{{site_name}}</code> - <?php _e('Your site name', 'snn'); ?></li>
                                        <li><code>{{site_url}}</code> - <?php _e('Your site URL', 'snn'); ?></li>
                                        <li><code>{{product_name}}</code> - <?php _e('Purchased product', 'snn'); ?></li>
                                        <li><code>{{username}}</code> - <?php _e("User's username", 'snn'); ?></li>
                                        <li><code>{{password}}</code> - <?php _e('Generated password', 'snn'); ?></li>
                                        <li><code>{{email}}</code> - <?php _e("User's email", 'snn'); ?></li>
                                        <li><code>{{login_url}}</code> - <?php _e('WordPress login URL', 'snn'); ?></li>
                                        <li><code>{{password_reset_url}}</code> - <?php _e('Password reset link', 'snn'); ?></li>
                                    </ul>
                                    <h4>💡 <?php _e('Tips:', 'snn'); ?></h4>
                                    <ul style="margin: 10px 0; padding-left: 20px;">
                                        <li><?php _e('Full HTML support - style your email as you wish!', 'snn'); ?></li>
                                        <li><?php _e('Use dynamic tags by wrapping them in double curly braces: {{tag_name}}', 'snn'); ?></li>
                                        <li><?php _e('The template above shows the default email structure', 'snn'); ?></li>
                                        <li><?php _e('Emails are sent as HTML, so you can use any HTML tags and inline CSS', 'snn'); ?></li>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- Cron Job Settings Section -->
                <div class="gumroad-section">
                    <h2><?php _e('Cron Job Settings', 'snn'); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="cron_interval"><?php _e('Check Interval (seconds)', 'snn'); ?></label></th>
                            <td>
                                <input type="number" name="cron_interval" id="cron_interval" value="<?php echo esc_attr($settings['cron_interval']); ?>" class="small-text" min="60" />
                                <p class="description"><?php _e('How often to check for new sales (default: 120 seconds)', 'snn'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="sales_limit"><?php _e('Sales to Check', 'snn'); ?></label></th>
                            <td>
                                <input type="number" name="sales_limit" id="sales_limit" value="<?php echo esc_attr($settings['sales_limit']); ?>" class="small-text" min="1" max="200" />
                                <p class="description"><?php _e('Number of recent sales to check each time (default: 50)', 'snn'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <?php submit_button(); ?>
            </form>
        </div>
        
        <script>
        var gumroadProducts = [];
        
        function togglePassword(id) {
            var input = document.getElementById(id);
            input.type = input.type === "password" ? "text" : "password";
        }
        
        function testApiConnection() {
            var token = document.getElementById('access_token').value;
            var resultDiv = document.getElementById('api-test-result');
            
            if (!token) {
                resultDiv.innerHTML = '<p style="color: red;">⚠️ Please enter an access token first.</p>';
                return;
            }
            
            resultDiv.innerHTML = '<p><span class="spinner is-active" style="float: none;"></span> Testing connection and fetching products...</p>';
            
            // First test the API
            jQuery.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'gumroad_test_api',
                    token: token,
                    nonce: '<?php echo wp_create_nonce('gumroad_test_api'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        resultDiv.innerHTML = '<p style="color: green;">✓ ' + response.data.message + '</p>';
                        // Now fetch products
                        fetchProducts(token);
                    } else {
                        resultDiv.innerHTML = '<p style="color: red;">✗ ' + response.data.message + '</p>';
                    }
                },
                error: function() {
                    resultDiv.innerHTML = '<p style="color: red;">✗ Connection test failed.</p>';
                }
            });
        }
        
        function fetchProducts(token) {
            jQuery('#products-loading').show();
            jQuery('#products-notice').hide();
            
            jQuery.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'gumroad_fetch_products',
                    token: token,
                    nonce: '<?php echo wp_create_nonce('gumroad_fetch_products'); ?>'
                },
                success: function(response) {
                    jQuery('#products-loading').hide();
                    if (response.success) {
                        gumroadProducts = response.data.products;
                        displayProducts(response.data.products);
                        jQuery('#products-list').show();
                    } else {
                        alert('Failed to fetch products: ' + response.data.message);
                    }
                },
                error: function() {
                    jQuery('#products-loading').hide();
                    alert('Failed to fetch products. Please try again.');
                }
            });
        }
        
        function displayProducts(products) {
            var tbody = jQuery('#products-tbody');
            tbody.empty();
            
            if (products.length === 0) {
                tbody.append('<tr><td colspan="4" style="text-align: center;">No products found. Create products in your Gumroad dashboard first.</td></tr>');
                return;
            }
            
            // Add hidden input with products data for persistence
            jQuery('#products-data-input').remove();
            jQuery('<input type="hidden" id="products-data-input" name="products_data" value="' + escapeHtml(JSON.stringify(products)) + '" />').insertAfter('#products-tbody');
            
            // Show save reminder
            jQuery('#save-products-reminder').remove();
            jQuery('<div id="save-products-reminder" style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 15px 0;"><p><strong>⚠️ Products loaded successfully!</strong> Please scroll down and click <strong>"Save Changes"</strong> to persist these products.</p></div>').insertBefore('#products-list');
            
            var savedProductRoles = <?php echo json_encode($product_roles); ?>;
            
            products.forEach(function(product) {
                var statusBadge = product.published 
                    ? '<span style="color: green;">● Published</span>' 
                    : '<span style="color: gray;">● Unpublished</span>';
                
                var savedRoles = savedProductRoles[product.id] || [];
                
                var rolesHtml = '<div class="product-roles-checkboxes">';
                <?php
                global $wp_roles;
                foreach ($wp_roles->roles as $role_key => $role_info) {
                    echo "rolesHtml += '<label style=\"display: block; margin: 3px 0;\"><input type=\"checkbox\" name=\"product_roles[' + product.id + '][]\" value=\"" . esc_js($role_key) . "\" ' + (savedRoles.indexOf('" . esc_js($role_key) . "') !== -1 ? 'checked' : '') + ' /> " . esc_js($role_info['name']) . "</label>';";
                }
                ?>
                rolesHtml += '</div>';
                
                var row = '<tr>' +
                    '<td><strong>' + escapeHtml(product.name) + '</strong></td>' +
                    '<td><code>' + escapeHtml(product.id) + '</code></td>' +
                    '<td>' + statusBadge + '</td>' +
                    '<td>' + rolesHtml + '<input type="hidden" name="product_ids[]" value="' + escapeHtml(product.id) + '" /></td>' +
                    '</tr>';
                
                tbody.append(row);
            });
        }
        
        function escapeHtml(text) {
            var map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, function(m) { return map[m]; });
        }
        
        // Load products on page load
        jQuery(document).ready(function($) {
            // Load products from saved settings on page load
            var savedProducts = <?php echo json_encode(isset($settings['products']) ? $settings['products'] : array()); ?>;
            
            if (savedProducts && savedProducts.length > 0) {
                // Products exist in database, display them immediately
                gumroadProducts = savedProducts;
                displayProducts(savedProducts);
                $('#products-notice').hide();
                $('#products-list').show();
            } else {
                // No products saved, check if token exists to show helpful message
                var token = $('#access_token').val();
                if (token && token.length > 0) {
                    $('#products-notice').html('<p><strong>👉 Products not loaded yet.</strong></p><p>Scroll up to the "API Connection" section and click <strong>"Test & Fetch Products"</strong> to load your Gumroad products.</p>');
                }
            }
        });
        </script>
        
        <style>
        .gumroad-section { 
            padding: 20px; 
            background: white; 
            border: 1px solid #ccd0d4; 
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .gumroad-section h2 {
            margin-top: 0;
            padding-bottom: 10px;
            border-bottom: 2px solid #0073aa;
        }
        .product-role-row { margin-bottom: 10px; }
        .product-roles-checkboxes label { font-weight: normal; }
        #products-list table { margin-top: 20px; }
        #products-list th { padding: 10px; background: #f5f5f5; font-weight: 600; }
        #products-list td { padding: 10px; vertical-align: top; }
        </style>
        <?php
    }
    
    /**
     * Render product role row (deprecated, kept for compatibility)
     */
    private function render_product_role_row($product_id, $role) {
        // This method is no longer used but kept for backward compatibility
        return;
    }
    
    /**
     * Save settings
     */
    private function save_settings($post_data) {
        // Get existing settings to preserve products if not updated
        $existing_settings = get_option($this->option_name, array());
        
        $settings = array(
            'access_token' => isset($post_data['access_token']) ? sanitize_text_field($post_data['access_token']) : '',
            'auto_create_users' => isset($post_data['auto_create_users']) ? true : false,
            'default_roles' => isset($post_data['default_roles']) ? array_map('sanitize_text_field', $post_data['default_roles']) : array(),
            'cron_interval' => isset($post_data['cron_interval']) ? intval($post_data['cron_interval']) : 120,
            'sales_limit' => isset($post_data['sales_limit']) ? intval($post_data['sales_limit']) : 50,
            'send_welcome_email' => isset($post_data['send_welcome_email']) ? true : false,
            'email_subject' => isset($post_data['email_subject']) ? sanitize_text_field($post_data['email_subject']) : '',
            'email_template' => isset($post_data['email_template']) ? wp_kses_post($post_data['email_template']) : '',
            'log_limit' => isset($post_data['log_limit']) ? intval($post_data['log_limit']) : 500,
            'user_list_per_page' => isset($post_data['user_list_per_page']) ? intval($post_data['user_list_per_page']) : 20,
            'product_roles' => array(),
            'products' => isset($existing_settings['products']) ? $existing_settings['products'] : array()
        );
        
        // Process product roles
        if (isset($post_data['product_roles']) && is_array($post_data['product_roles'])) {
            foreach ($post_data['product_roles'] as $product_id => $roles) {
                if (is_array($roles) && !empty($roles)) {
                    $settings['product_roles'][sanitize_text_field($product_id)] = array_map('sanitize_text_field', $roles);
                }
            }
        }
        
        // Process products data
        if (isset($post_data['products_data'])) {
            $products_json = stripslashes($post_data['products_data']);
            $products = json_decode($products_json, true);
            if (is_array($products)) {
                $settings['products'] = $products;
            }
        }
        
        update_option($this->option_name, $settings);
        
        // Reschedule cron with new interval
        $this->schedule_cron();
    }
    
    /**
     * Test API connection
     */
    public function test_api_connection() {
        check_ajax_referer('gumroad_test_api', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $token = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '';
        
        if (empty($token)) {
            wp_send_json_error(array('message' => 'Access token is required'));
        }
        
        $response = wp_remote_get('https://api.gumroad.com/v2/user?access_token=' . $token);
        
        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => 'Connection failed: ' . $response->get_error_message()));
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['success']) && $data['success']) {
            $user_name = isset($data['user']['name']) ? $data['user']['name'] : 'Unknown';
            wp_send_json_success(array('message' => 'Connected successfully! User: ' . $user_name));
        } else {
            $error_message = isset($data['message']) ? $data['message'] : 'Unknown error';
            wp_send_json_error(array('message' => 'API Error: ' . $error_message));
        }
    }
    
    /**
     * Fetch products from Gumroad
     */
    public function fetch_products() {
        check_ajax_referer('gumroad_fetch_products', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $token = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '';
        
        if (empty($token)) {
            wp_send_json_error(array('message' => 'Access token is required'));
        }
        
        $response = wp_remote_get('https://api.gumroad.com/v2/products?access_token=' . $token);
        
        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => 'Failed to fetch products: ' . $response->get_error_message()));
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['success']) && $data['success'] && isset($data['products'])) {
            $products = array();
            foreach ($data['products'] as $product) {
                $products[] = array(
                    'id' => $product['id'],
                    'name' => $product['name'],
                    'published' => $product['published']
                );
            }
            wp_send_json_success(array('products' => $products));
        } else {
            $error_message = isset($data['message']) ? $data['message'] : 'Unknown error';
            wp_send_json_error(array('message' => 'API Error: ' . $error_message));
        }
    }
    
    /**
     * Logs page
     */
    public function logs_page() {
        // Handle form submission for log limit
        if (isset($_POST['gumroad_log_settings_nonce']) && wp_verify_nonce($_POST['gumroad_log_settings_nonce'], 'gumroad_save_log_settings')) {
            $settings = get_option($this->option_name);
            $settings['log_limit'] = isset($_POST['log_limit']) ? intval($_POST['log_limit']) : 500;
            update_option($this->option_name, $settings);
            echo '<div class="notice notice-success"><p>' . __('Log settings saved successfully!', 'snn') . '</p></div>';
        }
        
        $settings = get_option($this->option_name);
        $logs = get_option($this->log_option_name, array());
        $per_page = 20;
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $total_logs = count($logs);
        $total_pages = ceil($total_logs / $per_page);
        $offset = ($page - 1) * $per_page;
        $current_logs = array_slice($logs, $offset, $per_page);
        
        ?>
        <div class="wrap">
            <h1><?php _e('API Logs', 'snn'); ?></h1>
            
            <!-- Log Settings Section -->
            <div class="gumroad-section" style="padding: 20px; background: white; border: 1px solid #ccd0d4; margin-bottom: 20px; border-radius: 4px;">
                <h2 style="margin-top: 0; padding-bottom: 10px; border-bottom: 2px solid #0073aa;"><?php _e('Log Settings', 'snn'); ?></h2>
                <form method="post" action="">
                    <?php wp_nonce_field('gumroad_save_log_settings', 'gumroad_log_settings_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="log_limit"><?php _e('Log Limit', 'snn'); ?></label></th>
                            <td>
                                <input type="number" name="log_limit" id="log_limit" value="<?php echo esc_attr($settings['log_limit']); ?>" class="small-text" min="50" />
                                <p class="description"><?php _e('Maximum number of logs to keep (default: 500)', 'snn'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button(__('Save Settings', 'snn'), 'primary', 'submit', false); ?>
                </form>
            </div>
            
            <div style="margin: 20px 0;">
                <button type="button" class="button" onclick="if(confirm('Are you sure you want to clear all logs?')) clearLogs();"><?php _e('Clear All Logs', 'snn'); ?></button>
                <span style="margin-left: 15px;"><?php printf(__('Total: %d logs', 'snn'), $total_logs); ?></span>
            </div>
            
            <?php if (empty($current_logs)): ?>
                <p><?php _e('No logs found.', 'snn'); ?></p>
            <?php else: ?>
                <div id="logs-container">
                    <?php foreach ($current_logs as $index => $log): ?>
                        <div class="log-entry" style="background: white; padding: 15px; margin-bottom: 10px; border: 1px solid #ccd0d4; border-radius: 4px;">
                            <div class="log-header" style="cursor: pointer; display: flex; justify-content: space-between; align-items: center;" onclick="toggleLog(<?php echo $index; ?>)">
                                <div>
                                    <strong><?php echo esc_html($log['type']); ?></strong>
                                    <span style="margin-left: 15px; color: #666; font-size: 12px;"><?php echo esc_html($log['timestamp']); ?></span>
                                </div>
                                <span class="dashicons dashicons-arrow-down-alt2" id="icon-<?php echo $index; ?>"></span>
                            </div>
                            <div class="log-details" id="log-<?php echo $index; ?>" style="display: none; margin-top: 10px; padding-top: 10px; border-top: 1px solid #eee;">
                                <pre style="background: #f5f5f5; padding: 10px; overflow-x: auto; font-size: 12px;"><?php echo esc_html(print_r($log['data'], true)); ?></pre>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <?php if ($total_pages > 1): ?>
                    <div class="tablenav bottom">
                        <div class="tablenav-pages">
                            <?php
                            echo paginate_links(array(
                                'base' => add_query_arg('paged', '%#%'),
                                'format' => '',
                                'prev_text' => __('&laquo;'),
                                'next_text' => __('&raquo;'),
                                'total' => $total_pages,
                                'current' => $page
                            ));
                            ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        
        <script>
        function toggleLog(index) {
            var details = document.getElementById('log-' + index);
            var icon = document.getElementById('icon-' + index);
            if (details.style.display === 'none') {
                details.style.display = 'block';
                icon.classList.remove('dashicons-arrow-down-alt2');
                icon.classList.add('dashicons-arrow-up-alt2');
            } else {
                details.style.display = 'none';
                icon.classList.remove('dashicons-arrow-up-alt2');
                icon.classList.add('dashicons-arrow-down-alt2');
            }
        }
        
        function clearLogs() {
            jQuery.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'gumroad_clear_logs',
                    nonce: '<?php echo wp_create_nonce('gumroad_clear_logs'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    }
                }
            });
        }
        </script>
        <?php
    }
    
    /**
     * Clear logs
     */
    public function clear_logs() {
        check_ajax_referer('gumroad_clear_logs', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error();
        }
        
        update_option($this->log_option_name, array());
        wp_send_json_success();
    }
    
    /**
     * Users page - Display all users created by Gumroad API
     */
    public function users_page() {
        // Handle form submission for per page setting
        if (isset($_POST['gumroad_user_list_settings_nonce']) && wp_verify_nonce($_POST['gumroad_user_list_settings_nonce'], 'gumroad_save_user_list_settings')) {
            $settings = get_option($this->option_name);
            $settings['user_list_per_page'] = isset($_POST['user_list_per_page']) ? max(1, intval($_POST['user_list_per_page'])) : 20;
            update_option($this->option_name, $settings);
            echo '<div class="notice notice-success"><p>' . __('Settings saved successfully!', 'snn') . '</p></div>';
        }
        
        $settings = get_option($this->option_name);
        $per_page = isset($settings['user_list_per_page']) ? intval($settings['user_list_per_page']) : 20;
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        
        // Query users with Gumroad metadata
        $args = array(
            'meta_key' => 'gumroad_sale_id',
            'meta_compare' => 'EXISTS',
            'number' => $per_page,
            'paged' => $page,
            'orderby' => 'registered',
            'order' => 'DESC'
        );
        
        $user_query = new WP_User_Query($args);
        $users = $user_query->get_results();
        $total_users = $user_query->get_total();
        $total_pages = ceil($total_users / $per_page);
        
        ?>
        <div class="wrap">
            <h1><?php _e('Gumroad Users', 'snn'); ?></h1>
            
            <!-- Per Page Settings Section -->
            <div class="gumroad-section" style="padding: 15px; background: white; border: 1px solid #ccd0d4; margin-bottom: 20px; border-radius: 4px;">
                <form method="post" action="" style="display: flex; align-items: center; gap: 15px;">
                    <?php wp_nonce_field('gumroad_save_user_list_settings', 'gumroad_user_list_settings_nonce'); ?>
                    <label for="user_list_per_page"><strong><?php _e('Users per page:', 'snn'); ?></strong></label>
                    <input type="number" name="user_list_per_page" id="user_list_per_page" value="<?php echo esc_attr($per_page); ?>" class="small-text" min="1" max="100" />
                    <?php submit_button(__('Save', 'snn'), 'primary', 'submit', false); ?>
                    <span style="margin-left: auto; color: #666;"><?php printf(__('Total: %d users', 'snn'), $total_users); ?></span>
                </form>
            </div>
            
            <?php if (empty($users)): ?>
                <div style="background: white; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; text-align: center;">
                    <p><?php _e('No users found. Users created through Gumroad purchases will appear here.', 'snn'); ?></p>
                </div>
            <?php else: ?>
                <div id="users-container">
                    <?php foreach ($users as $index => $user): 
                        $sale_id = get_user_meta($user->ID, 'gumroad_sale_id', true);
                        $product_name = get_user_meta($user->ID, 'gumroad_product_name', true);
                        $product_id = get_user_meta($user->ID, 'gumroad_product_id', true);
                        $created_date = get_user_meta($user->ID, 'gumroad_created_date', true);
                        $email_sent = get_user_meta($user->ID, 'gumroad_email_sent', true);
                        $email_sent_date = get_user_meta($user->ID, 'gumroad_email_sent_date', true);
                        $sale_data = get_user_meta($user->ID, 'gumroad_sale_data', true);
                        $assigned_roles = get_user_meta($user->ID, 'gumroad_assigned_roles', true);
                        $last_purchase_date = get_user_meta($user->ID, 'gumroad_last_purchase_date', true);
                        $purchase_history = get_user_meta($user->ID, 'gumroad_purchase_history', true);
                        
                        $user_data = get_userdata($user->ID);
                        $registered_date = $user_data->user_registered;
                        $roles = $user_data->roles;
                        
                        // Email preview (first 50 chars)
                        $email_preview = strlen($user->user_email) > 30 ? substr($user->user_email, 0, 30) . '...' : $user->user_email;
                    ?>
                        <div class="user-entry" style="background: white; padding: 15px; margin-bottom: 10px; border: 1px solid #ccd0d4; border-radius: 4px;">
                            <div class="user-header" style="cursor: pointer; display: flex; justify-content: space-between; align-items: center; gap: 15px;" onclick="toggleUser(<?php echo $user->ID; ?>)">
                                <div style="flex: 1; display: flex; align-items: center; gap: 15px;">
                                    <span class="dashicons dashicons-arrow-right" id="icon-<?php echo $user->ID; ?>" style="color: #2271b1;"></span>
                                    <div style="flex: 1;">
                                        <strong style="font-size: 14px;"><?php echo esc_html($user->user_login); ?></strong>
                                        <span style="color: #666; margin-left: 10px; font-size: 13px;"><?php echo esc_html($email_preview); ?></span>
                                    </div>
                                </div>
                                <div style="display: flex; gap: 15px; align-items: center; font-size: 12px; color: #666;">
                                    <span><strong><?php _e('Product:', 'snn'); ?></strong> <?php echo esc_html($product_name ? $product_name : 'N/A'); ?></span>
                                    <span><strong><?php _e('Created:', 'snn'); ?></strong> <?php echo esc_html($created_date ? $created_date : $registered_date); ?></span>
                                    <?php if ($email_sent === 'yes'): ?>
                                        <span style="color: green;">✓ <?php _e('Email Sent', 'snn'); ?></span>
                                    <?php else: ?>
                                        <span style="color: #999;">✗ <?php _e('No Email', 'snn'); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="user-details" id="user-<?php echo $user->ID; ?>" style="display: none; margin-top: 15px; padding-top: 15px; border-top: 1px solid #eee;">
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                                    <!-- Left Column -->
                                    <div>
                                        <h3 style="margin-top: 0; font-size: 14px; color: #2271b1;"><?php _e('User Information', 'snn'); ?></h3>
                                        <table class="widefat" style="font-size: 13px;">
                                            <tr><th style="width: 40%; padding: 8px; background: #f9f9f9;"><?php _e('User ID', 'snn'); ?></th><td style="padding: 8px;"><?php echo esc_html($user->ID); ?></td></tr>
                                            <tr><th style="padding: 8px; background: #f9f9f9;"><?php _e('Username', 'snn'); ?></th><td style="padding: 8px;"><?php echo esc_html($user->user_login); ?></td></tr>
                                            <tr><th style="padding: 8px; background: #f9f9f9;"><?php _e('Email', 'snn'); ?></th><td style="padding: 8px;"><?php echo esc_html($user->user_email); ?></td></tr>
                                            <tr><th style="padding: 8px; background: #f9f9f9;"><?php _e('Registered', 'snn'); ?></th><td style="padding: 8px;"><?php echo esc_html($registered_date); ?></td></tr>
                                            <tr><th style="padding: 8px; background: #f9f9f9;"><?php _e('Current Roles', 'snn'); ?></th><td style="padding: 8px;"><?php echo esc_html(implode(', ', $roles)); ?></td></tr>
                                        </table>
                                        
                                        <h3 style="margin-top: 20px; font-size: 14px; color: #2271b1;"><?php _e('Gumroad Information', 'snn'); ?></h3>
                                        <table class="widefat" style="font-size: 13px;">
                                            <tr><th style="width: 40%; padding: 8px; background: #f9f9f9;"><?php _e('Sale ID', 'snn'); ?></th><td style="padding: 8px;"><code><?php echo esc_html($sale_id ? $sale_id : 'N/A'); ?></code></td></tr>
                                            <tr><th style="padding: 8px; background: #f9f9f9;"><?php _e('Product Name', 'snn'); ?></th><td style="padding: 8px;"><?php echo esc_html($product_name ? $product_name : 'N/A'); ?></td></tr>
                                            <tr><th style="padding: 8px; background: #f9f9f9;"><?php _e('Product ID', 'snn'); ?></th><td style="padding: 8px;"><code><?php echo esc_html($product_id ? $product_id : 'N/A'); ?></code></td></tr>
                                            <tr><th style="padding: 8px; background: #f9f9f9;"><?php _e('Created Date', 'snn'); ?></th><td style="padding: 8px;"><?php echo esc_html($created_date ? $created_date : 'N/A'); ?></td></tr>
                                            <tr><th style="padding: 8px; background: #f9f9f9;"><?php _e('Assigned Roles', 'snn'); ?></th><td style="padding: 8px;"><?php echo esc_html($assigned_roles ? implode(', ', json_decode($assigned_roles, true)) : 'N/A'); ?></td></tr>
                                        </table>
                                        
                                        <h3 style="margin-top: 20px; font-size: 14px; color: #2271b1;"><?php _e('Email Status', 'snn'); ?></h3>
                                        <table class="widefat" style="font-size: 13px;">
                                            <tr><th style="width: 40%; padding: 8px; background: #f9f9f9;"><?php _e('Email Sent', 'snn'); ?></th><td style="padding: 8px;"><?php echo $email_sent === 'yes' ? '<span style="color: green;">✓ Yes</span>' : '<span style="color: #999;">✗ No</span>'; ?></td></tr>
                                            <?php if ($email_sent === 'yes'): ?>
                                            <tr><th style="padding: 8px; background: #f9f9f9;"><?php _e('Email Sent Date', 'snn'); ?></th><td style="padding: 8px;"><?php echo esc_html($email_sent_date); ?></td></tr>
                                            <?php endif; ?>
                                        </table>
                                    </div>
                                    
                                    <!-- Right Column -->
                                    <div>
                                        <?php if ($last_purchase_date): ?>
                                        <h3 style="margin-top: 0; font-size: 14px; color: #2271b1;"><?php _e('Last Purchase', 'snn'); ?></h3>
                                        <table class="widefat" style="font-size: 13px; margin-bottom: 20px;">
                                            <tr><th style="width: 40%; padding: 8px; background: #f9f9f9;"><?php _e('Date', 'snn'); ?></th><td style="padding: 8px;"><?php echo esc_html($last_purchase_date); ?></td></tr>
                                            <tr><th style="padding: 8px; background: #f9f9f9;"><?php _e('Product', 'snn'); ?></th><td style="padding: 8px;"><?php echo esc_html(get_user_meta($user->ID, 'gumroad_last_product_name', true)); ?></td></tr>
                                        </table>
                                        <?php endif; ?>
                                        
                                        <?php if ($purchase_history): 
                                            $history = json_decode($purchase_history, true);
                                            if (is_array($history) && !empty($history)):
                                        ?>
                                        <h3 style="margin-top: 0; font-size: 14px; color: #2271b1;"><?php _e('Purchase History', 'snn'); ?></h3>
                                        <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; border-radius: 4px;">
                                            <table class="widefat" style="font-size: 12px;">
                                                <thead>
                                                    <tr>
                                                        <th style="padding: 6px; background: #f5f5f5;"><?php _e('Date', 'snn'); ?></th>
                                                        <th style="padding: 6px; background: #f5f5f5;"><?php _e('Product', 'snn'); ?></th>
                                                        <th style="padding: 6px; background: #f5f5f5;"><?php _e('Roles Added', 'snn'); ?></th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach (array_reverse($history) as $purchase): ?>
                                                    <tr>
                                                        <td style="padding: 6px;"><?php echo esc_html($purchase['date']); ?></td>
                                                        <td style="padding: 6px;"><?php echo esc_html($purchase['product_name']); ?></td>
                                                        <td style="padding: 6px;"><?php echo esc_html(implode(', ', $purchase['roles_added'])); ?></td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <?php endif; endif; ?>
                                        
                                        <h3 style="margin-top: 20px; font-size: 14px; color: #2271b1;"><?php _e('Raw Sale Data', 'snn'); ?></h3>
                                        <div style="background: #f5f5f5; padding: 10px; border-radius: 4px; max-height: 300px; overflow-y: auto;">
                                            <pre style="margin: 0; font-size: 11px; white-space: pre-wrap; word-wrap: break-word;"><?php 
                                                if ($sale_data) {
                                                    $decoded_data = json_decode($sale_data, true);
                                                    echo esc_html(print_r($decoded_data, true));
                                                } else {
                                                    echo 'No raw sale data available';
                                                }
                                            ?></pre>
                                        </div>
                                    </div>
                                </div>
                                
                                <div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #eee; display: flex; gap: 10px;">
                                    <a href="<?php echo admin_url('user-edit.php?user_id=' . $user->ID); ?>" class="button button-primary" target="_blank"><?php _e('Edit User', 'snn'); ?></a>
                                    <a href="mailto:<?php echo esc_attr($user->user_email); ?>" class="button"><?php _e('Send Email', 'snn'); ?></a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <?php if ($total_pages > 1): ?>
                    <div class="tablenav bottom" style="padding: 15px; background: white; border: 1px solid #ccd0d4; border-radius: 4px; margin-top: 10px;">
                        <div class="tablenav-pages">
                            <?php
                            echo paginate_links(array(
                                'base' => add_query_arg('paged', '%#%'),
                                'format' => '',
                                'prev_text' => __('&laquo; Previous'),
                                'next_text' => __('Next &raquo;'),
                                'total' => $total_pages,
                                'current' => $page,
                                'type' => 'plain'
                            ));
                            ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Save Button at Bottom -->
                <div style="margin-top: 20px; padding: 15px; background: white; border: 1px solid #ccd0d4; border-radius: 4px;">
                    <form method="post" action="" style="display: flex; align-items: center; gap: 15px;">
                        <?php wp_nonce_field('gumroad_save_user_list_settings', 'gumroad_user_list_settings_nonce'); ?>
                        <label for="user_list_per_page_bottom"><strong><?php _e('Users per page:', 'snn'); ?></strong></label>
                        <input type="number" name="user_list_per_page" id="user_list_per_page_bottom" value="<?php echo esc_attr($per_page); ?>" class="small-text" min="1" max="100" />
                        <?php submit_button(__('Save', 'snn'), 'primary', 'submit', false); ?>
                    </form>
                </div>
            <?php endif; ?>
        </div>
        
        <script>
        function toggleUser(userId) {
            var details = document.getElementById('user-' + userId);
            var icon = document.getElementById('icon-' + userId);
            if (details.style.display === 'none') {
                details.style.display = 'block';
                icon.classList.remove('dashicons-arrow-right');
                icon.classList.add('dashicons-arrow-down');
            } else {
                details.style.display = 'none';
                icon.classList.remove('dashicons-arrow-down');
                icon.classList.add('dashicons-arrow-right');
            }
        }
        </script>
        
        <style>
        .user-entry:hover {
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: box-shadow 0.2s;
        }
        .user-header:hover {
            background: #f9f9f9;
        }
        .tablenav-pages {
            text-align: center;
        }
        .tablenav-pages .page-numbers {
            padding: 5px 10px;
            margin: 0 2px;
            border: 1px solid #ccd0d4;
            background: white;
            text-decoration: none;
            display: inline-block;
        }
        .tablenav-pages .page-numbers.current {
            background: #2271b1;
            color: white;
            border-color: #2271b1;
        }
        .tablenav-pages .page-numbers:hover:not(.current) {
            background: #f0f0f1;
        }
        </style>
        <?php
    }
}

// Initialize the plugin
new Gumroad_API_WordPress();



