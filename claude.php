<?php
/**
 * Plugin Name: Claude Chat Interface
 * Description: Adds a Claude AI chat interface to your WordPress site using a shortcode.
 * Version: 1.0
 * Author: Volkan Kücükbudak
 */

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

// Add settings page in WordPress admin
function claude_chat_settings_page() {
    add_options_page('Claude Chat Settings', 'Claude Chat', 'manage_options', 'claude-chat-settings', 'claude_chat_settings_page_html');
}
add_action('admin_menu', 'claude_chat_settings_page');

// Settings page HTML
function claude_chat_settings_page_html() {
    // TODO: Implement settings form (API key, model selection, etc.)
    echo '<h2>Claude Chat Settings</h2>';
    echo '<p>Configure your Claude API settings here.</p>';
}
