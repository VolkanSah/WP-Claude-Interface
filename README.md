# Claude Chat Interface

A WordPress plugin that integrates the Claude AI chat interface into your website using a shortcode.

## Features

- Easy integration with WordPress using a shortcode
- Admin settings page for API configuration
- Customizable chat interface
- Support for Claude API parameters (temperature, max tokens, etc.)
- AJAX-based chat functionality for smooth user experience

## Installation

1. Upload the `claude-chat-interface` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to 'Settings' > 'Claude Chat' to configure your API settings

## Usage

Use the shortcode `[claude_chat]` to display the chat interface on any page or post.

## Configuration

In the WordPress admin panel, navigate to 'Settings' > 'Claude Chat' to configure the following options:

- API Key: Your Claude API key
- Model: The Claude model to use
- Temperature: Controls randomness in responses (0.0 to 1.0)
- Max Tokens: Maximum number of tokens in the response

## Customization

- The chat interface can be styled by modifying the `css/claude-chat.css` file
- Additional functionality can be added by editing the `js/claude-chat.js` file

## Requirements

- WordPress 5.0 or higher
- PHP 7.0 or higher
- Valid Claude API key

## Support

For support or feature requests, please open an issue on the GitHub repository.

## License

This plugin is licensed under the DBAD License

## Copyright 
**Volkan Sah*
