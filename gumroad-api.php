<?php
/** 
 * Plugin Name: Gumroad API WordPress
 * Plugin URI: https://github.com/sinanisler/gumroad-api-wordpress
 * Description: Connect your WordPress site with Gumroad to automatically create user accounts when customers make a purchase.
 * Version: 0.1
 * Author: sinanisler
 * Author URI: https://sinanisler.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: snn
 */

if (!defined('ABSPATH')) {
    exit;
}

class Gumroad_API_WordPress {
    
    private $option_name = 'gumroad_api_settings';
    private $log_option_name = 'gumroad_api_logs';
    
    public function __construct() {
        // Admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        
        // REST API endpoint for webhooks
        add_action('rest_api_init', array($this, 'register_webhook_endpoint'));
        
        // Cron job
        add_action('gumroad_api_check_sales', array($this, 'check_recent_sales'));
        add_filter('cron_schedules', array($this, 'add_custom_cron_interval'));
        
        // Activation/Deactivation
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // AJAX handlers
        add_action('wp_ajax_gumroad_test_api', array($this, 'test_api_connection'));
        add_action('wp_ajax_gumroad_clear_logs', array($this, 'clear_logs'));
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Set default options
        $default_options = array(
            'access_token' => '',
            'default_role' => 'subscriber',
            'product_roles' => array(),
            'cron_interval' => 120,
            'sales_limit' => 50,
            'send_welcome_email' => true,
            'email_subject' => 'Welcome to {{site_name}}!',
            'email_template' => $this->get_default_email_template(),
            'log_limit' => 500
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
            30
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
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('gumroad_api_settings_group', $this->option_name);
    }
    
    /**
     * Register webhook endpoint
     */
    public function register_webhook_endpoint() {
        register_rest_route('gumroad-api/v1', '/webhook', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_webhook'),
            'permission_callback' => '__return_true'
        ));
    }
    
    /**
     * Handle webhook from Gumroad
     */
    public function handle_webhook($request) {
        $params = $request->get_params();
        
        $this->log_activity('Webhook received', $params);
        
        // Process the sale
        $result = $this->process_sale($params);
        
        if (is_wp_error($result)) {
            $this->log_activity('Webhook error', array('error' => $result->get_error_message()));
            return new WP_REST_Response(array('success' => false, 'message' => $result->get_error_message()), 400);
        }
        
        return new WP_REST_Response(array('success' => true, 'user_id' => $result), 200);
    }
    
    /**
     * Check recent sales via API
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
                
                if (!in_array($sale_id, $processed_sales)) {
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
        
        // Check if user exists
        $user = get_user_by('email', $email);
        
        $settings = get_option($this->option_name);
        $default_role = isset($settings['default_role']) ? $settings['default_role'] : 'subscriber';
        $product_roles = isset($settings['product_roles']) ? $settings['product_roles'] : array();
        
        // Determine role
        $role = $default_role;
        if (!empty($product_id) && isset($product_roles[$product_id])) {
            $role = $product_roles[$product_id];
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
            $user->set_role($role);
            
            // Send welcome email
            if (isset($settings['send_welcome_email']) && $settings['send_welcome_email']) {
                $this->send_welcome_email($user, $password, $product_name);
            }
            
            $this->log_activity('User created', array(
                'user_id' => $user_id,
                'email' => $email,
                'product' => $product_name,
                'role' => $role
            ));
            
            return $user_id;
        } else {
            // Update existing user role if needed
            if (!in_array($role, (array) $user->roles)) {
                $user->set_role($role);
                
                $this->log_activity('User role updated', array(
                    'user_id' => $user->ID,
                    'email' => $email,
                    'product' => $product_name,
                    'new_role' => $role
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
        
        wp_mail($user->user_email, $subject, $message, $headers);
    }
    
    /**
     * Get default email template
     */
    private function get_default_email_template() {
        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #0073aa; color: white; padding: 20px; text-align: center; }
        .content { background: #f9f9f9; padding: 30px; }
        .button { display: inline-block; padding: 12px 24px; background: #0073aa; color: white; text-decoration: none; border-radius: 4px; margin: 10px 0; }
        .credentials { background: white; padding: 15px; border-left: 4px solid #0073aa; margin: 20px 0; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Welcome to {{site_name}}!</h1>
        </div>
        <div class="content">
            <p>Hi there!</p>
            <p>Thank you for purchasing <strong>{{product_name}}</strong>! Your account has been created automatically.</p>
            
            <div class="credentials">
                <h3>Your Login Credentials:</h3>
                <p><strong>Username:</strong> {{username}}</p>
                <p><strong>Password:</strong> {{password}}</p>
                <p><strong>Email:</strong> {{email}}</p>
            </div>
            
            <p>You can access your account using the button below:</p>
            <p style="text-align: center;">
                <a href="{{login_url}}" class="button">Login to Your Account</a>
            </p>
            
            <p>If you prefer to reset your password, use this link:</p>
            <p style="text-align: center;">
                <a href="{{password_reset_url}}" class="button">Reset Password</a>
            </p>
            
            <p><strong>Important:</strong> Please keep this email safe as it contains your login credentials.</p>
        </div>
        <div class="footer">
            <p>&copy; {{site_name}} - <a href="{{site_url}}">{{site_url}}</a></p>
        </div>
    </div>
</body>
</html>';
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
        $webhook_url = rest_url('gumroad-api/v1/webhook');
        
        ?>
        <div class="wrap">
            <h1><?php _e('Gumroad API Settings', 'snn'); ?></h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('gumroad_save_settings', 'gumroad_settings_nonce'); ?>
                
                <h2 class="nav-tab-wrapper">
                    <a href="#tab-connection" class="nav-tab nav-tab-active"><?php _e('Connection', 'snn'); ?></a>
                    <a href="#tab-roles" class="nav-tab"><?php _e('User Roles', 'snn'); ?></a>
                    <a href="#tab-email" class="nav-tab"><?php _e('Welcome Email', 'snn'); ?></a>
                    <a href="#tab-cron" class="nav-tab"><?php _e('Cron Settings', 'snn'); ?></a>
                </h2>
                
                <!-- Connection Tab -->
                <div id="tab-connection" class="tab-content" style="display:block;">
                    <h2><?php _e('API Connection', 'snn'); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php _e('Webhook URL', 'snn'); ?></th>
                            <td>
                                <input type="text" value="<?php echo esc_url($webhook_url); ?>" readonly class="regular-text" id="webhook-url" />
                                <button type="button" class="button" onclick="copyWebhookUrl()"><?php _e('Copy', 'snn'); ?></button>
                                <p class="description"><?php _e('Copy this URL and add it to your Gumroad product settings under "Ping"', 'snn'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="access_token"><?php _e('Access Token', 'snn'); ?></label></th>
                            <td>
                                <input type="password" name="access_token" id="access_token" value="<?php echo esc_attr($settings['access_token']); ?>" class="regular-text" />
                                <button type="button" class="button" onclick="togglePassword('access_token')"><?php _e('Show/Hide', 'snn'); ?></button>
                                <button type="button" class="button" onclick="testApiConnection()"><?php _e('Test Connection', 'snn'); ?></button>
                                <p class="description"><?php _e('Generate this from your Gumroad application settings', 'snn'); ?></p>
                                <div id="api-test-result"></div>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- Roles Tab -->
                <div id="tab-roles" class="tab-content" style="display:none;">
                    <h2><?php _e('User Role Assignment', 'snn'); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="default_role"><?php _e('Default User Role', 'snn'); ?></label></th>
                            <td>
                                <select name="default_role" id="default_role">
                                    <?php wp_dropdown_roles($settings['default_role']); ?>
                                </select>
                                <p class="description"><?php _e('Default role assigned to new users', 'snn'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Product-Specific Roles', 'snn'); ?></th>
                            <td>
                                <div id="product-roles">
                                    <?php
                                    $product_roles = isset($settings['product_roles']) ? $settings['product_roles'] : array();
                                    if (!empty($product_roles)) {
                                        foreach ($product_roles as $product_id => $role) {
                                            $this->render_product_role_row($product_id, $role);
                                        }
                                    } else {
                                        $this->render_product_role_row('', '');
                                    }
                                    ?>
                                </div>
                                <button type="button" class="button" onclick="addProductRole()"><?php _e('Add Product Role', 'snn'); ?></button>
                                <p class="description"><?php _e('Assign specific roles based on product IDs. Find product IDs in your Gumroad dashboard.', 'snn'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- Email Tab -->
                <div id="tab-email" class="tab-content" style="display:none;">
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
                
                <!-- Cron Tab -->
                <div id="tab-cron" class="tab-content" style="display:none;">
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
                        <tr>
                            <th scope="row"><label for="log_limit"><?php _e('Log Limit', 'snn'); ?></label></th>
                            <td>
                                <input type="number" name="log_limit" id="log_limit" value="<?php echo esc_attr($settings['log_limit']); ?>" class="small-text" min="50" />
                                <p class="description"><?php _e('Maximum number of logs to keep (default: 500)', 'snn'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <?php submit_button(); ?>
            </form>
        </div>
        
        <script>
        function copyWebhookUrl() {
            var copyText = document.getElementById("webhook-url");
            copyText.select();
            copyText.setSelectionRange(0, 99999);
            document.execCommand("copy");
            alert("<?php _e('Webhook URL copied to clipboard!', 'snn'); ?>");
        }
        
        function togglePassword(id) {
            var input = document.getElementById(id);
            input.type = input.type === "password" ? "text" : "password";
        }
        
        function testApiConnection() {
            var token = document.getElementById('access_token').value;
            var resultDiv = document.getElementById('api-test-result');
            
            if (!token) {
                resultDiv.innerHTML = '<p style="color: red;">Please enter an access token first.</p>';
                return;
            }
            
            resultDiv.innerHTML = '<p>Testing connection...</p>';
            
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
                    } else {
                        resultDiv.innerHTML = '<p style="color: red;">✗ ' + response.data.message + '</p>';
                    }
                },
                error: function() {
                    resultDiv.innerHTML = '<p style="color: red;">Connection test failed.</p>';
                }
            });
        }
        
        // Tab switching
        jQuery(document).ready(function($) {
            $('.nav-tab').click(function(e) {
                e.preventDefault();
                $('.nav-tab').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');
                $('.tab-content').hide();
                $($(this).attr('href')).show();
            });
        });
        
        function addProductRole() {
            var container = document.getElementById('product-roles');
            var index = container.children.length;
            var html = '<div class="product-role-row" style="margin-bottom: 10px;">' +
                '<input type="text" name="product_roles_id[]" placeholder="Product ID" class="regular-text" /> ' +
                '<select name="product_roles_role[]">' +
                <?php
                global $wp_roles;
                foreach ($wp_roles->roles as $role_key => $role) {
                    echo "'<option value=\"" . esc_attr($role_key) . "\">" . esc_html($role['name']) . "</option>' + ";
                }
                ?>
                '</select> ' +
                '<button type="button" class="button" onclick="removeProductRole(this)">Remove</button>' +
                '</div>';
            container.insertAdjacentHTML('beforeend', html);
        }
        
        function removeProductRole(button) {
            button.parentElement.remove();
        }
        </script>
        
        <style>
        .nav-tab-wrapper { margin-bottom: 20px; }
        .tab-content { padding: 20px; background: white; border: 1px solid #ccd0d4; border-top: none; }
        .product-role-row { margin-bottom: 10px; }
        </style>
        <?php
    }
    
    /**
     * Render product role row
     */
    private function render_product_role_row($product_id, $role) {
        ?>
        <div class="product-role-row" style="margin-bottom: 10px;">
            <input type="text" name="product_roles_id[]" value="<?php echo esc_attr($product_id); ?>" placeholder="Product ID" class="regular-text" />
            <select name="product_roles_role[]">
                <?php wp_dropdown_roles($role); ?>
            </select>
            <button type="button" class="button" onclick="removeProductRole(this)"><?php _e('Remove', 'snn'); ?></button>
        </div>
        <?php
    }
    
    /**
     * Save settings
     */
    private function save_settings($post_data) {
        $settings = array(
            'access_token' => isset($post_data['access_token']) ? sanitize_text_field($post_data['access_token']) : '',
            'default_role' => isset($post_data['default_role']) ? sanitize_text_field($post_data['default_role']) : 'subscriber',
            'cron_interval' => isset($post_data['cron_interval']) ? intval($post_data['cron_interval']) : 120,
            'sales_limit' => isset($post_data['sales_limit']) ? intval($post_data['sales_limit']) : 50,
            'send_welcome_email' => isset($post_data['send_welcome_email']) ? true : false,
            'email_subject' => isset($post_data['email_subject']) ? sanitize_text_field($post_data['email_subject']) : '',
            'email_template' => isset($post_data['email_template']) ? wp_kses_post($post_data['email_template']) : '',
            'log_limit' => isset($post_data['log_limit']) ? intval($post_data['log_limit']) : 500,
            'product_roles' => array()
        );
        
        // Process product roles
        if (isset($post_data['product_roles_id']) && isset($post_data['product_roles_role'])) {
            $product_ids = $post_data['product_roles_id'];
            $roles = $post_data['product_roles_role'];
            
            for ($i = 0; $i < count($product_ids); $i++) {
                if (!empty($product_ids[$i]) && !empty($roles[$i])) {
                    $settings['product_roles'][sanitize_text_field($product_ids[$i])] = sanitize_text_field($roles[$i]);
                }
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
     * Logs page
     */
    public function logs_page() {
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
}

// Initialize the plugin
new Gumroad_API_WordPress();



