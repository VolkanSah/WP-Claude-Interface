<?php
/**
 * Plugin Name: Claude Chat Interface
 * Description: Adds a Claude AI chat interface to your WordPress site using a shortcode.
 * Version: 1.0
 * Author: 
 */

// Register settings
function claude_chat_register_settings() {
    register_setting('claude_chat_options', 'claude_chat_api_key');
    register_setting('claude_chat_options', 'claude_chat_model');
    register_setting('claude_chat_options', 'claude_chat_temperature');
    register_setting('claude_chat_options', 'claude_chat_max_tokens');
}
add_action('admin_init', 'claude_chat_register_settings');

// Enqueue necessary scripts and styles
function claude_chat_enqueue_scripts() {
    wp_enqueue_style('claude-chat-style', plugin_dir_url(__FILE__) . 'css/claude-chat.css');
    wp_enqueue_script('claude-chat-script', plugin_dir_url(__FILE__) . 'js/claude-chat.js', array('jquery'), '1.0', true);
    wp_localize_script('claude-chat-script', 'claudeChat', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('claude-chat-nonce')
    ));
}
add_action('wp_enqueue_scripts', 'claude_chat_enqueue_scripts');

// Shortcode to display the chat interface
function claude_chat_shortcode() {
    ob_start();
    ?>
    <div id="claude-chat-interface">
        <div id="claude-chat-messages"></div>
        <input type="text" id="claude-chat-input" placeholder="Ask Claude something...">
        <button id="claude-chat-submit">Send</button>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('claude_chat', 'claude_chat_shortcode');

// AJAX handler for chat requests
function claude_chat_ajax_handler() {
    check_ajax_referer('claude-chat-nonce', 'nonce');
    
    $message = sanitize_text_field($_POST['message']);
    
    // TODO: Implement actual API call to Claude
    $response = "This is where we'd call the Claude API with the message: " . $message;
    
    wp_send_json_success($response);
}
add_action('wp_ajax_claude_chat', 'claude_chat_ajax_handler');
add_action('wp_ajax_nopriv_claude_chat', 'claude_chat_ajax_handler');

function claude_chat_settings_page_html() {
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <form action="options.php" method="post">
            <?php
            settings_fields('claude_chat_options');
            do_settings_sections('claude-chat-settings');
            submit_button('Save Settings');
            ?>
        </form>
    </div>
    <?php
}

function claude_chat_settings_init() {
    add_settings_section(
        'claude_chat_settings_section',
        'Claude API Settings',
        'claude_chat_settings_section_callback',
        'claude-chat-settings'
    );

    add_settings_field(
        'claude_chat_api_key',
        'API Key',
        'claude_chat_text_field_callback',
        'claude-chat-settings',
        'claude_chat_settings_section',
        array('label_for' => 'claude_chat_api_key')
    );

    add_settings_field(
        'claude_chat_model',
        'Model',
        'claude_chat_text_field_callback',
        'claude-chat-settings',
        'claude_chat_settings_section',
        array('label_for' => 'claude_chat_model')
    );

    add_settings_field(
        'claude_chat_temperature',
        'Temperature',
        'claude_chat_number_field_callback',
        'claude-chat-settings',
        'claude_chat_settings_section',
        array('label_for' => 'claude_chat_temperature', 'min' => 0, 'max' => 1, 'step' => 0.1)
    );

    add_settings_field(
        'claude_chat_max_tokens',
        'Max Tokens',
        'claude_chat_number_field_callback',
        'claude-chat-settings',
        'claude_chat_settings_section',
        array('label_for' => 'claude_chat_max_tokens', 'min' => 1, 'max' => 2048)
    );
}
add_action('admin_init', 'claude_chat_settings_init');

function claude_chat_settings_section_callback($args) {
    echo '<p>Enter your Claude API settings below:</p>';
}

function claude_chat_text_field_callback($args) {
    $option = get_option($args['label_for']);
    echo '<input type="text" id="' . esc_attr($args['label_for']) . '" name="' . esc_attr($args['label_for']) . '" value="' . esc_attr($option) . '" class="regular-text">';
}
function claude_chat_number_field_callback($args) {
    $option = get_option($args['label_for']);
    echo '<input type="number" id="' . esc_attr($args['label_for']) . '" name="' . esc_attr($args['label_for']) . '" value="' . esc_attr($option) . '" class="regular-text" min="' . esc_attr($args['min']) . '" max="' . esc_attr($args['max']) . '" step="' . (isset($args['step']) ? esc_attr($args['step']) : '1') . '">';
}

function claude_chat_api_request($message) {
    $api_key = get_option('claude_chat_api_key');
    $model = get_option('claude_chat_model');
    $temperature = get_option('claude_chat_temperature');
    $max_tokens = get_option('claude_chat_max_tokens');

    $url = 'https://api.anthropic.com/v1/completions';

    $headers = array(
        'Content-Type' => 'application/json',
        'Authorization' => 'Bearer ' . $api_key,
    );

    $body = array(
        'model' => $model,
        'prompt' => $message,
        'max_tokens_to_sample' => intval($max_tokens),
        'temperature' => floatval($temperature),
    );

    $response = wp_remote_post($url, array(
        'headers' => $headers,
        'body' => json_encode($body),
        'timeout' => 60,
    ));

    if (is_wp_error($response)) {
        return 'Error: ' . $response->get_error_message();
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (isset($data['completion'])) {
        return $data['completion'];
    } else {
        return 'Error: Unable to get a response from Claude API';
    }
}

function claude_chat_ajax_handler() {
    check_ajax_referer('claude-chat-nonce', 'nonce');
    
    $message = sanitize_text_field($_POST['message']);
    
    $response = claude_chat_api_request($message);
    
    wp_send_json_success($response);
}




