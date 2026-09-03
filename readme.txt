
=== Claude Chat Interface ===
Description: This Claude Chat Interface integrates the Claude AI chat interface into your WordPress site using a shortcode.
Contributors: aicodecraft, turtle-engr
Donate link: https://aicodecraft.io/donate
Tags: chat, AI, Claude, WordPress
Requires at least: 6.0
Tested up to: 7.1
Stable tag: VERSION
License: DBAD
License URI: https://dbad-license.org/

== Description ==

The Claude Chat Interface plugin allows you to integrate the Claude AI
chat interface into your WordPress website. You can easily configure
the plugin via the WordPress admin panel and use a shortcode to embed
the chat interface anywhere on your site.

How do I display the chat interface?  Use the shortcode
`[claude_chat]` to display the chat interface on any page or post.

Where do I get the API key?  You need to register with Claude AI
account to get your API key.

== Screenshots ==

1. Admin settings page for Claude Chat Interface.
   ![Claude 3 WordPress Plugin](claude_set.png)

2. Chat interface displayed on a WordPress page.
   ![Claude 3 WordPress Plugin](claude3.png)

== Installation ==

1. With the Plugins menu, upload claude-chat-interface-VERSION.zip
2. Install and activate.
3. Navigate to 'Settings' > 'Claude Chat' to configure your API settings.

== Changelog ==

= 2.5.0 =
* Cleaned up build process.

= 2.3 =
* Put version number on pages.

= 2.2 =
* Added new feature: fetch-url, and pre-fetch-urls

= 1.7 =
* Removed the Additional Prompt feature. It did not work well and it
  clutters the code.

* Added memory limit protections. When the 'Hostinger Easy Onboarding'
  plugin is active with 'NextGEN Gallery' plugin, an out-of-memory
  error is thrown when saving in Claude Settings form.  'Hostinger
  Easy Onboarding' is now disabled and I'll consider replacing
  NextGen.

= 1.6 =
* Scroll the output up so it is visible.

= 1.5 =
* Log user questions and responses

= 1.4 =
* Output format fixes for mobile.

= 1.3 =
* Security fixes.

= 1.2 =
* Additional prefix prompt.

= 1.1 =
* Prefix prompt enhancement.

= 1.0 =
* Initial release of Claude Chat Interface. Please configure your API
  settings after installation.
