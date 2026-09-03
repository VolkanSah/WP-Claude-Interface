<?php
/*
 * Plugin Name: Claude Chat Interface
 * Plugin URI: https://github.com/TurtleEngr/WP-Claude-Interface/tree/main
 * Description: Adds a Claude AI chat interface to your WordPress site using a shortcode.
 * Version: VERSION
 * Text Domain: claude
 * Author: Volkan Kücükbudak, enh: TurtleEngr
 */

/* Lock out script kiddies: die an direct call */
if (! defined('ABSPATH')) {
    exit;
}

/*
 * ========================================
 * Globals
 * ========================================
 */
 
/* Define the available models */
define('cgClaudeChatModels', [
        'claude-3-haiku-20240307'      => 'Claude 3.0 Haiku',
        'claude-3-5-haiku-20241022'    => 'Claude 3.5 Haiku',
        'claude-haiku-4-5-20251001'    => 'Claude 4.5 Haiku',
        'claude-3-5-sonnet-20241022'   => 'Claude 3.5 Sonnet',
        'claude-3-7-sonnet-20250219'   => 'Claude 3.7 Sonnet',
        'claude-sonnet-4-5-20250929'   => 'Claude 4.5 Sonnet',
    ]);

/*
 * (memory): Tunable limits to protect against pathological inputs and
 * responses. Raising these should only be necessary if a legitimate use case
 * is being clipped — in which case something is probably also wrong.
 *
 * Max body size (bytes) we will read from the Claude API. Well above any
 * legitimate response; an overage means something is wrong and we should
 * fail fast rather than consume all available PHP memory inside
 * wp-includes/Requests/src/Requests.php during response parsing.
 */
define('cgClaudeChatMaxResponseBytes', 4 * 1024 * 1024); /* 4 MB */

/* Max characters dumped into claude.log when an API error occurs. The raw
 * response body is *not* a useful debugging aid past the first few KB, and
 * we don't want a single bad response to fill the disk or hold a huge
 * string in memory.
 */
define('cgClaudeChatMaxLogDumpChars', 4096);

/* Max length (bytes) of the Prefix Prompt stored as an option. 64 KB is
 * roughly 10,000 words — more than a page. Anything larger almost
 * certainly means a mispaste.
 */
define('cgClaudeChatMaxPrefixPrompt', 65536);

/*
 * fetch_url tool limits.
 *
 * cgClaudeChatFetchTimeOut - Seconds allowed for a single URL fetch. On
 * overrun the fetch is abandoned and no content is returned for it.
 *                                
 * cgClaudeChatResponseBudget - Wall-clock seconds allowed for the
 * whole tool_use loop. Once exceeded, no further URLs are fetched and
 * we answer with whatever text we already have.
 *
 * CAVEAT: the budget is checked *between* steps.  An in-flight Claude
 * API call is not interrupted, so a slow API round trip can overrun
 * the budget (the API call timeout is still 60s). If that matters,
 * lower the wp_remote_post() timeout in fClaudeChatApiSend().
 */
define('cgClaudeChatFetchTimeOut', 5);
define('cgClaudeChatResponseBudget', 20);

/* Max bytes read from a fetched page, and max characters of extracted text
 * handed back to Claude. The byte cap protects PHP memory; the character cap
 * protects the token budget — a single large page can otherwise crowd out the
 * Prefix Prompt and the user's actual question.
 */
define('cgClaudeChatMaxFetchBytes', 256 * 1024); /* 256 KB */
define('cgClaudeChatMaxFetchChars', 20000);

/* Max number of send/tool_result round trips. The response budget is the
 * primary stop condition; this is a backstop so a model that keeps asking for
 * cheap, fast fetches cannot loop indefinitely inside the budget.
 */
define('cgClaudeChatMaxToolRounds', 5);

/* Pre-fetch list limits. Content is cached in a transient for this many
 * seconds, keyed by a hash of the URL list.
 */
define('cgClaudeChatPreFetchTtl', 3600); /* 1 hour */
define('cgClaudeChatMaxPreFetchUrls', 10);

/*
 * ========================================
 * Register settings
 * ========================================
 */
 
function fClaudeChatRegisterSettings() {
    register_setting('claude_chat_options', 'claude_chat_api_key');
    register_setting('claude_chat_options', 'claude_chat_model');
    register_setting('claude_chat_options', 'claude_chat_temperature');
    register_setting('claude_chat_options', 'claude_chat_max_tokens');
    register_setting('claude_chat_options', 'claude_chat_follow_links', [
            'sanitize_callback' => 'fClaudeChatSanitizeFollowLinks',
        ]);
    register_setting('claude_chat_options', 'claude_chat_prefetch_urls', [
            'sanitize_callback' => 'fClaudeChatSanitizePreFetchUrls',
        ]);
    /* FIX (memory): custom sanitize callback enforces a length cap so a
     * pathologically large paste cannot be written to wp_options.
     */
    register_setting('claude_chat_options', 'claude_chat_prefix_prompt', [
            'sanitize_callback' => 'fClaudeChatSanitizePrefixPrompt',
        ]);
}
add_action('admin_init', 'fClaudeChatRegisterSettings');

/*
 * FIX (memory): Sanitize callback for the Prefix Prompt.
 * Runs sanitize_textarea_field first, then clamps the length to
 * cgClaudeChatMaxPrefixPrompt. If truncation occurs, a
 * settings-error notice is registered so the user sees what happened
 * on the settings screen.
 */
function fClaudeChatSanitizePrefixPrompt( $value ) {
    $value = sanitize_textarea_field( $value );
    $len   = strlen( $value );
    if ( $len > cgClaudeChatMaxPrefixPrompt ) {
        $value = substr( $value, 0, cgClaudeChatMaxPrefixPrompt );
        add_settings_error(
            'claude_chat_prefix_prompt',
            'claude_chat_prefix_prompt_truncated',
            sprintf(
                /* translators: 1: submitted size, 2: allowed size */
                esc_html__( 'Prefix Prompt was %1$d bytes; truncated to the %2$d-byte limit.', 'claude-chat' ),
                $len,
                cgClaudeChatMaxPrefixPrompt
            ),
            'warning'
        );
    }
    return $value;
}

/*
 * Normalise the Follow Links checkbox to '1' or ''.
 *
 * The settings form posts a hidden '0' before the checkbox (see
 * fClaudeChatCheckboxFieldCallback), so an unchecked box still submits a
 * value and the option is correctly cleared.
 */
function fClaudeChatSanitizeFollowLinks( $value ) {
    return ( $value === '1' ) ? '1' : '';
}

/*
 * Validate the pre-fetch URL list: one URL per line.
 *
 * Invalid lines are dropped with a settings-error notice rather than silently
 * accepted, so a typo does not turn into a silent no-op at request time. The
 * list is capped at cgClaudeChatMaxPreFetchUrls entries.
 */
function fClaudeChatSanitizePreFetchUrls( $value ) {
    $lines   = preg_split( '/\r\n|\r|\n/', (string) $value );
    $urls    = array();
    $skipped = 0;

    foreach ( $lines as $line ) {
        $line = trim( $line );
        if ( $line === '' ) {
            continue;
        }
        if ( count( $urls ) >= cgClaudeChatMaxPreFetchUrls ) {
            $skipped++;
            continue;
        }
        $url = esc_url_raw( $line );
        /* wp_http_validate_url() rejects non-http(s) schemes and blocks
           loopback / private / link-local addresses. */
        if ( $url === '' || ! wp_http_validate_url( $url ) ) {
            $skipped++;
            continue;
        }
        $urls[] = $url;
    }

    if ( $skipped > 0 ) {
        add_settings_error(
            'claude_chat_prefetch_urls',
            'claude_chat_prefetch_urls_skipped',
            sprintf(
                /* translators: 1: number skipped, 2: allowed maximum */
                esc_html__( '%1$d pre-fetch line(s) dropped: invalid, non-http(s), private address, or beyond the %2$d-URL limit.', 'claude-chat' ),
                $skipped,
                cgClaudeChatMaxPreFetchUrls
            ),
            'warning'
        );
    }

    return implode( "\n", $urls );
}


/* Enqueue necessary scripts and styles */
function fClaudeChatEnqueueScripts() {
    wp_enqueue_style('claude-chat-style', plugin_dir_url(__FILE__) . 'css/claude-chat.css');
    wp_enqueue_script('claude-chat-script', plugin_dir_url(__FILE__) . 'js/claude-chat.js', array('jquery'), 'VERSION', true);
    wp_localize_script('claude-chat-script', 'claudeChat', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('claude-chat-nonce'),
        ));
}
add_action('wp_enqueue_scripts', 'fClaudeChatEnqueueScripts');

/* Shortcode to display the chat interface */
function fClaudeChatShortCode() {
    ob_start();
?>
    <div id="claude-chat-interface">
        <div id="claude-chat-messages"></div>
        <textarea id="claude-chat-input" placeholder="Ask Claude something..." rows="3"></textarea>
        <button id="claude-chat-submit">Send</button> (Version: VERSION)
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('claude_chat', 'fClaudeChatShortCode');

/*
 * Transient-based rate limiter — max 10 requests per minute per IP.
 * Returns true when the request is allowed, false when the limit is exceeded.
 */
function fClaudeChatCheckRateLimit() {
    $ip            = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
    $transient_key = 'claude_chat_rate_' . md5($ip);
    $count         = get_transient($transient_key);

    if ($count === false) {
        /* First request in this window — start the counter with a
           60-second TTL. */
        set_transient($transient_key, 1, 60);
        return true;
    }

    if (intval($count) >= 10) {
        return false; /* Rate limit exceeded. */
    }

    /* Increment without resetting the existing TTL by reusing the same key. */
    set_transient($transient_key, intval($count) + 1, 60);
    return true;
}

/* AJAX handler for chat requests */
function fClaudeChatAjaxHandler() {
    check_ajax_referer('claude-chat-nonce', 'nonce');

    /* Enforce rate limit before doing any further work. */
    if ( ! fClaudeChatCheckRateLimit() ) {
        wp_send_json_error('Rate limit exceeded. Please wait a moment before sending another message.');
        return;
    }

    /* Use sanitize_textarea_field so newlines in multi-line messages
       are preserved (sanitize_text_field strips them). */
    $message = sanitize_textarea_field($_POST['message']);

    $response = fClaudeChatApiRequest($message);
    if ($response) {
        wp_send_json_success($response);
    } else {
        wp_send_json_error('Error: No response from API');
    }
}
add_action('wp_ajax_claude_chat',        'fClaudeChatAjaxHandler');
add_action('wp_ajax_nopriv_claude_chat', 'fClaudeChatAjaxHandler');

/*
 * ========================================
 * Logging helpers
 * ========================================
 */

/*
 * Returns the absolute filesystem path to a file inside the claude uploads
 * subdirectory, creating the directory if it does not yet exist.
 *
 *                            (default: 'claude')
 *
 * @param string  $log_subdir Subdirectory name inside wp-content/uploads/
 * @param string  $log_file   Filename inside that subdirectory.
 * @return string|false       Absolute path on success, false on failure.
 */
function fClaudeChatGetLogPath( $log_subdir = 'claude', $log_file = '' ) {
    $upload_info = wp_upload_dir();

    if ( ! empty( $upload_info['error'] ) ) {
        return false;
    }

    /* e.g. /var/www/html/wp-content/uploads/claude */
    $dir = trailingslashit( $upload_info['basedir'] ) . $log_subdir;

    if ( ! is_dir( $dir ) ) {
        /* wp_mkdir_p() creates intermediate directories and returns
           false on failure. */
        if ( ! wp_mkdir_p( $dir ) ) {
            return false;
        }
    }

    return $log_file !== '' ? trailingslashit( $dir ) . $log_file : $dir;
}


/*
 * (memory): Truncate a value for safe inclusion in an error log.
 *
 * Non-strings are first rendered with print_r(); the result is then
 * clamped to $max_chars characters with a trailing marker noting the
 * original length. This prevents a large API error payload — e.g. an
 * HTML error page from a misrouted request — from being held in PHP
 * memory and then appended to claude.log in full.
 *
 * @param mixed $value     The value to render.
 * @param int   $max_chars Maximum characters in the returned string.
 * @return string          Safe-to-log string, never longer than
 *                         $max_chars + a short truncation marker.
 */
function fClaudeChatTruncateForLog( $value, $max_chars = cgClaudeChatMaxLogDumpChars ) {
    if ( ! is_string( $value ) ) {
        $value = print_r( $value, true );
    }
    $len = strlen( $value );
    if ( $len > $max_chars ) {
        $value = substr( $value, 0, $max_chars )
               . "\n... [truncated, {$len} bytes total]";
    }
    return $value;
}

/*
 * Appends a user-message / Claude-response entry to claude_log.org in
 * Org-mode format:
 *
 *   ** YYYY-MM-DD HH:MM message
 *   $message
 *   *** response
 *   $response
 *
 * @param string  $message  The sanitised user message sent to the API.
 * @param string  $response The text returned by the Claude API.
 */
function fClaudeChatLogMessage( $message, $response ) {
    $log_subdir = 'claude';
    $log_file   = 'claude_log.org';

    $path = fClaudeChatGetLogPath( $log_subdir, $log_file );
    if ( $path === false ) {
        return; /* Could not resolve / create the directory — fail silently. */
    }

    $date = new DateTime('now', new DateTimeZone('America/Los_Angeles'));
    $timestamp = $date->format('Y-m-d H:i:s T');

    $entry  = "** {$timestamp} message\n";
    $entry .= $message . "\n";
    $entry .= "*** response\n";
    $entry .= $response . "\n\n";

    /* error_log() mode 3 appends to an arbitrary file. */
    error_log( $entry, 3, $path );
}


/*
 * Appends an error entry to claude.log inside the same uploads subdirectory.
 *
 * @param string  $error_type    Short label, e.g. 'HTTP Error', 'API Error'.
 * @param string  $error_message Full error detail.
 */
function fClaudeChatLogError( $error_type, $error_message ) {
    $log_subdir = 'claude';
    $log_file   = 'claude.log';

    $path = fClaudeChatGetLogPath( $log_subdir, $log_file );
    if ( $path === false ) {
        return;
    }

    $log_message = date( 'Y-m-d H:i:s' ) . " - {$error_type}: {$error_message}\n";
    error_log( $log_message, 3, $path );
}

/*
 * ========================================
 * URL fetching — shared by the fetch_url tool and the pre-fetch list
 * ========================================
 */

/*
 * Reduce an HTML document to plain visible text.
 *
 * script/style/noscript bodies are removed first: wp_strip_all_tags() drops
 * the tags but keeps their contents, which would otherwise hand Claude a pile
 * of JavaScript. The result is whitespace-collapsed and clamped to
 * cgClaudeChatMaxFetchChars characters.
 *
 * @param string $html Raw response body.
 * @return string      Plain text, possibly truncated.
 */
function fClaudeChatHtmlToText( $html ) {
    $stripped = preg_replace( '#<(script|style|noscript)\b[^>]*>.*?</\1>#is', ' ', $html );
    /* preg_replace() returns null on failure (e.g. hitting the backtrack
       limit on a pathological document) — fall back to the raw body. */
    if ( $stripped !== null ) {
        $html = $stripped;
    }

    $text = wp_strip_all_tags( $html );
    $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    $text = preg_replace( '/[ \t]+/', ' ', $text );
    $text = preg_replace( '/(\s*\n\s*){3,}/', "\n\n", $text );
    $text = trim( (string) $text );

    if ( function_exists( 'mb_strlen' ) ) {
        if ( mb_strlen( $text, 'UTF-8' ) > cgClaudeChatMaxFetchChars ) {
            $text = mb_substr( $text, 0, cgClaudeChatMaxFetchChars, 'UTF-8' )
                  . "\n... [truncated]";
        }
    } elseif ( strlen( $text ) > cgClaudeChatMaxFetchChars ) {
        $text = substr( $text, 0, cgClaudeChatMaxFetchChars ) . "\n... [truncated]";
    }

    return $text;
}

/*
 * Fetch one URL server-side and return its visible text.
 *
 * SECURITY: wp_safe_remote_get() applies wp_http_validate_url(), which rejects
 * non-http(s) schemes and blocks loopback, private, and link-local addresses.
 * That is what stops a visitor from talking the model into fetching an
 * internal service or a cloud metadata endpoint. On a public-facing page,
 * consider tightening this to an explicit hostname allow-list.
 *
 * Honours cgClaudeChatFetchTimeOut (cFetchN): on timeout the call is
 * abandoned and false is returned.
 *
 * @param string $url Absolute http(s) URL.
 * @return string|false Extracted text, or false on any failure.
 */
function fClaudeChatFetchUrl( $url ) {
    $url = esc_url_raw( trim( (string) $url ) );

    if ( $url === '' || ! wp_http_validate_url( $url ) ) {
        fClaudeChatLogError( 'Fetch Rejected', fClaudeChatTruncateForLog( $url, 256 ) );
        return false;
    }

    $response = wp_safe_remote_get( $url, array(
            'timeout'             => cgClaudeChatFetchTimeOut,
            'redirection'         => 3,
            'limit_response_size' => cgClaudeChatMaxFetchBytes,
            'user-agent'          => 'WP-Claude-Interface/VERSION',
        ) );

    if ( is_wp_error( $response ) ) {
        fClaudeChatLogError( 'Fetch Error', $url . ' - ' . $response->get_error_message() );
        return false;
    }

    $code = intval( wp_remote_retrieve_response_code( $response ) );
    if ( $code !== 200 ) {
        fClaudeChatLogError( 'Fetch Error', $url . ' - HTTP ' . $code );
        return false;
    }

    return fClaudeChatHtmlToText( wp_remote_retrieve_body( $response ) );
}

/*
 * Build the pre-fetch system block from the configured URL list.
 *
 * Runs on every request regardless of the Follow Links setting, as specified.
 * The assembled block is cached in a transient keyed by a hash of the URL
 * list, so editing the list produces a new key and takes effect immediately;
 * re-saving an unchanged list reuses the existing cache until it expires.
 *
 * An empty result is cached too — if the target site is down we should not
 * retry the whole list on every single chat message.
 *
 * @return string System-prompt text, or '' when nothing is configured/fetched.
 */
function fClaudeChatGetPreFetchBlock() {
    $raw = trim( get_option( 'claude_chat_prefetch_urls', '' ) );
    if ( $raw === '' ) {
        return '';
    }

    $urls = array();
    foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
        $line = trim( $line );
        if ( $line !== '' ) {
            $urls[] = $line;
        }
        if ( count( $urls ) >= cgClaudeChatMaxPreFetchUrls ) {
            break;
        }
    }
    if ( empty( $urls ) ) {
        return '';
    }

    $cache_key = 'claude_chat_prefetch_' . md5( implode( "\n", $urls ) );
    $cached    = get_transient( $cache_key );
    if ( $cached !== false ) {
        return $cached;
    }

    $parts = array();
    foreach ( $urls as $url ) {
        $text = fClaudeChatFetchUrl( $url );
        if ( $text === false || $text === '' ) {
            continue;
        }
        $parts[] = "===== BEGIN {$url} =====\n{$text}\n===== END {$url} =====";
    }

    $block = '';
    if ( ! empty( $parts ) ) {
        $block = "Reference content already retrieved from the site. Use it directly; "
               . "do not fetch these URLs again.\n\n"
               . implode( "\n\n", $parts );
    }

    set_transient( $cache_key, $block, cgClaudeChatPreFetchTtl );
    return $block;
}


/*
 * Tool definition sent to the API when Follow Links is enabled.
 */
function fClaudeChatFetchUrl_tool_spec() {
    return array(
        'name'         => 'fetch_url',
        'description'  => 'Fetch a web page and return its visible text. Use this whenever the '
                        . 'instructions or the user ask you to read, list, check, or cite the '
                        . 'contents of a URL. Fetch each URL you need before answering; never '
                        . 'guess at page contents.',
        'input_schema' => array(
            'type'       => 'object',
            'properties' => array(
                'url' => array(
                    'type'        => 'string',
                    'description' => 'Absolute http(s) URL to fetch.',
                ),
            ),
            'required'   => array( 'url' ),
        ),
    );
}


/*
 * ========================================
 * Claude API request
 * ========================================
 */


/*
 * Collect every text block from an API response.
 *
 * The previous code read $data['content'][0]['text']. With tools enabled the
 * first block is frequently a tool_use block, so text must be gathered by
 * type rather than by position.
 */
function fClaudeChatCollectText( $data ) {
    if ( empty( $data['content'] ) || ! is_array( $data['content'] ) ) {
        return '';
    }

    $parts = array();
    foreach ( $data['content'] as $block ) {
        if ( isset( $block['type'], $block['text'] ) && $block['type'] === 'text' ) {
            $parts[] = $block['text'];
        }
    }

    return trim( implode( "\n", $parts ) );
}

/*
 * Send one request to the Messages API.
 *
 * @param array $args api_key, model, max_tokens, temperature, system,
 *                    messages, tools
 * @return array|string Decoded response array on success; a user-facing error
 *                      string on failure (already logged).
 */
function fClaudeChatApiSend( $args ) {
    /* Use the correct API-Endpoint. */
    $url = 'https://api.anthropic.com/v1/messages';

    $headers = array(
        'Content-Type'      => 'application/json',
        'x-api-key'         => $args['api_key'],
        'anthropic-version' => '2023-06-01',
        /* Required to enable cache_control on system/content blocks. */
        'anthropic-beta'    => 'prompt-caching-2024-07-31',
    );

    $body = array(
        'model'      => $args['model'],
        'max_tokens' => intval( $args['max_tokens'] ),
        'messages'   => $args['messages'],
    );

    if ( ! empty( $args['system'] ) ) {
        $body['system'] = $args['system'];
    }

    /* Only include temperature when set (0 is falsy but valid, so
     * check !== '')
     */
    if ( $args['temperature'] !== '' ) {
        $body['temperature'] = floatval( $args['temperature'] );
    }

    if ( ! empty( $args['tools'] ) ) {
        $body['tools'] = $args['tools'];
    }

    /*
     * FIX (memory): Cap the response body WordPress will read.
     *
     * Without `limit_response_size`, an unexpectedly large response —
     * e.g. an upstream proxy returning HTML, a malformed stream, or a
     * misrouted request — is read in full and then passed through
     * wp-includes/Requests/src/Requests.php::parse_response(), which
     * splits it with repeated substr() calls. Each substr roughly
     * doubles the string's memory footprint during parsing, so a
     * response of tens of MB can exhaust even a 1.5 GB memory_limit
     * and crash unrelated admin requests when another plugin later
     * triggers the same code path.
     *
     * With the cap set, an oversized response aborts cleanly inside
     * the transport and is surfaced here as a WP_Error, which the
     * existing is_wp_error() branch already handles.
     */
    $response = wp_remote_post( $url, array(
            'headers'             => $headers,
            'body'                => json_encode( $body ),
            'timeout'             => 60,
            'limit_response_size' => cgClaudeChatMaxResponseBytes,
        ) );

    if ( is_wp_error( $response ) ) {
        fClaudeChatLogError( 'HTTP Error', $response->get_error_message() );
        return 'Error: ' . $response->get_error_message();
    }

    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( isset( $data['error'] ) ) {
        /* FIX (memory): truncate the dumped payload so a huge error
           body cannot balloon the log file or PHP memory. */
        fClaudeChatLogError( 'API Error', fClaudeChatTruncateForLog( $data ) );
        return 'API Error: ' . $data['error']['message'];
    }

    if ( ! is_array( $data ) || ! isset( $data['content'] ) || ! is_array( $data['content'] ) ) {
        /* FIX (memory): same truncation rationale as above. */
        fClaudeChatLogError(
            'Unknown Error',
            'Unable to get a response from Claude API. Response: '
                . fClaudeChatTruncateForLog( $data )
        );
        return 'Error: Unable to get a response from Claude API.';
    }

    return $data;
}


/*
 * Claude API request with logging and, when Follow Links is enabled, a
 * tool_use loop that resolves fetch_url calls.
 *
 * Flow:
 *   send -> if the reply contains tool_use blocks, fetch each requested URL,
 *   append the assistant turn plus a matching tool_result for every tool_use
 *   id, and send again. Repeat until the model stops asking, the round cap is
 *   hit, or cgClaudeChatResponseBudget (cResponseN) is exhausted.
 *
 * Every tool_use block must receive a tool_result with the same id or the
 * next request is rejected, so failed and skipped fetches return an
 * explanatory result rather than being omitted.
 */
function fClaudeChatApiRequest( $message ) {
    $api_key       = get_option('claude_chat_api_key');
    $model         = get_option('claude_chat_model');
    $temperature   = get_option('claude_chat_temperature');
    $max_tokens    = get_option('claude_chat_max_tokens');
    $prefix_prompt = trim(get_option('claude_chat_prefix_prompt', ''));
    $follow_links  = ( get_option('claude_chat_follow_links', '') === '1' );

    $started = microtime( true );

    /*
     * Move the prefix prompt to the dedicated `system` parameter.
     *
     * Placing it in `system` gives it architectural separation from
     * the conversation turn — it cannot be overridden by "Ignore
     * previous instructions…" style user inputs and benefits from
     * Claude's distinct system-prompt handling.
     *
     * The array form is used (rather than a plain string) so that
     * cache_control can be set on the block, preserving the
     * prompt-caching benefit of the original implementation.
     *
     * Pre-fetched page content is appended as a second system block, and
     * cache_control moves to the last block so the whole system prefix is
     * cached. With no pre-fetch list configured this is identical to the
     * previous single-block behaviour.
     */
    $system_blocks = array();

    if ( $prefix_prompt !== '' ) {
        $system_blocks[] = array( 'type' => 'text', 'text' => $prefix_prompt );
    }

    $prefetch = fClaudeChatGetPreFetchBlock();
    if ( $prefetch !== '' ) {
        $system_blocks[] = array( 'type' => 'text', 'text' => $prefetch );
    }

    if ( ! empty( $system_blocks ) ) {
        $last = count( $system_blocks ) - 1;
        $system_blocks[ $last ]['cache_control'] = array( 'type' => 'ephemeral' );
    }

    $messages = array(
        array(
            'role'    => 'user',
            'content' => $message, /* plain string, no prefix bundled in here */
        ),
    );

    $tools      = $follow_links ? array( fClaudeChatFetchUrl_tool_spec() ) : array();
    $final_text = '';
    $timed_out  = false;

    for ( $round = 0; $round <= cgClaudeChatMaxToolRounds; $round++ ) {

        $data = fClaudeChatApiSend( array(
                'api_key'     => $api_key,
                'model'       => $model,
                'max_tokens'  => $max_tokens,
                'temperature' => $temperature,
                'system'      => $system_blocks,
                'messages'    => $messages,
                'tools'       => $tools,
            ) );

        if ( is_string( $data ) ) {
            return $data; /* Already logged, and safe to show the user. */
        }

        $text = fClaudeChatCollectText( $data );
        if ( $text !== '' ) {
            $final_text = $text;
        }

        /* Collect any fetch_url requests from this turn. */
        $tool_uses = array();
        foreach ( $data['content'] as $block ) {
            if ( isset( $block['type'] ) && $block['type'] === 'tool_use' ) {
                $tool_uses[] = $block;
            }
        }

        if ( ! $follow_links || empty( $tool_uses ) ) {
            break; /* Normal completion. */
        }

        if ( $round === cgClaudeChatMaxToolRounds ) {
            fClaudeChatLogError(
                'Tool Loop',
                'Reached the ' . cgClaudeChatMaxToolRounds . '-round cap; returning a partial answer.'
            );
            break;
        }

        if ( ( microtime( true ) - $started ) >= cgClaudeChatResponseBudget ) {
            $timed_out = true;
            fClaudeChatLogError(
                'Tool Loop',
                'Response budget of ' . cgClaudeChatResponseBudget
                    . 's exhausted before round ' . ( $round + 1 ) . '.'
            );
            break;
        }

        $results = array();
        foreach ( $tool_uses as $block ) {
            $result = array(
                'type'        => 'tool_result',
                'tool_use_id' => isset( $block['id'] ) ? $block['id'] : '',
            );

            /* Budget can run out partway through a batch of URLs. Remaining
               tool_use blocks still need a result, so return an error one
               instead of fetching. */
            if ( ( microtime( true ) - $started ) >= cgClaudeChatResponseBudget ) {
                $timed_out          = true;
                $result['content']  = 'Not fetched: the response time budget was exhausted. '
                                    . 'Answer with what you already have.';
                $result['is_error'] = true;
                $results[]          = $result;
                continue;
            }

            $name = isset( $block['name'] ) ? $block['name'] : '';
            $url  = isset( $block['input']['url'] ) ? $block['input']['url'] : '';

            if ( $name !== 'fetch_url' || $url === '' ) {
                $result['content']  = 'Unknown tool, or the url parameter was missing.';
                $result['is_error'] = true;
                $results[]          = $result;
                continue;
            }

            $text = fClaudeChatFetchUrl( $url );

            if ( $text === false || $text === '' ) {
                /* Per spec: a fetch that fails or times out returns nothing. */
                $result['content']  = 'Could not fetch ' . $url . ' (unreachable, blocked, non-200, '
                                    . 'or timed out after ' . cgClaudeChatFetchTimeOut . 's). '
                                    . 'Do not invent its contents.';
                $result['is_error'] = true;
            } else {
                $result['content'] = "Content of {$url}:\n\n{$text}";
            }

            $results[] = $result;
        }

        /* The assistant turn must be echoed back verbatim, followed by one
           user turn carrying every tool_result. */
        $messages[] = array( 'role' => 'assistant', 'content' => $data['content'] );
        $messages[] = array( 'role' => 'user',      'content' => $results );
    }

    if ( $final_text === '' ) {
        $final_text = $timed_out
            ? 'Error: Timed out while retrieving linked pages. Please try again.'
            : 'Error: Unable to get a response from Claude API.';
        fClaudeChatLogError( 'Empty Response', $final_text );
        return $final_text;
    }

    /* Log the user message and Claude response to claude_log.org. */
    fClaudeChatLogMessage( $message, $final_text );

    return $final_text;
}


/*
 * Clear Logs handler
 * Deletes claude_log.org and claude.log, then redirects back to the
 * settings page with a confirmation flag.
 */
function fClaudeChatClearLogs() {
    if ( ! current_user_can('manage_options') ) {
        wp_die( esc_html__('Unauthorized', 'claude-chat') );
    }
    check_admin_referer('fClaudeChatClearLogs_action', 'fClaudeChatClearLogs_nonce');

    foreach ( array('claude_log.org', 'claude.log') as $log_file ) {
        $path = fClaudeChatGetLogPath('claude', $log_file);
        if ( $path && file_exists($path) ) {
            wp_delete_file($path);
        }
        error_log( "* Log\n", 3, $path );
    }

    wp_redirect( add_query_arg(
        array('page' => 'claude-chat-settings', 'logs-cleared' => '1'),
        admin_url('options-general.php')
    ) );
    exit;
}
add_action('admin_post_fClaudeChatClearLogs', 'fClaudeChatClearLogs');


/*
 * ========================================
 * Add settings page
 * ========================================
 */
 
function fClaudeChatSettingsPage() {
    add_options_page(
        'Claude Chat Settings',
        'Claude Chat',
        'manage_options',
        'claude-chat-settings',
        'fClaudeChatSettingsPage_html'
    );
}
add_action('admin_menu', 'fClaudeChatSettingsPage');

/* Settings page HTML */
function fClaudeChatSettingsPage_html() {
    $homeUrl = home_url();
?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

        <?php if ( isset($_GET['logs-cleared']) && $_GET['logs-cleared'] === '1' ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e('Log files cleared successfully.', 'claude-chat'); ?></p>
        </div>
        <?php endif; ?>

        <form action="options.php" method="post">
            <?php
    settings_fields('claude_chat_options');
    do_settings_sections('claude-chat-settings');
    submit_button('Save Settings');
?>
        </form>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
              style="margin-top:12px;">
            <input type="hidden" name="action" value="fClaudeChatClearLogs">
            <?php wp_nonce_field('fClaudeChatClearLogs_action', 'fClaudeChatClearLogs_nonce'); ?>
            <?php submit_button('Clear Logs', 'delete', 'fClaudeChatClearLogs_submit', false);
            echo '<p>Before clearing the logs, they can be viewed at:<br>';
            echo '<a href="' . home_url('/wp-content/uploads/claude/claude_log.org') . '" target="_blank">';
            echo home_url('/wp-content/uploads/claude/claude_log.org') . '</a><br>';
            echo '<a href="' . home_url('/wp-content/uploads/claude/claude.log') . '" target="_blank">';
            echo home_url('/wp-content/uploads/claude/claude.log') . '</a></p>';
            ?>
        </form>
    </div>
    <?php
}

/* Initialize settings */
function fClaudeChatSettingsInit() {
    add_settings_section(
        'claude_chat_settings_section',
        'Claude API Settings',
        'fClaudeChatSettingsSection',
        'claude-chat-settings'
    );

    add_settings_field(
        'claude_chat_api_key',
        'API Key',
        'fClaudeChatApiKeyFieldCallback',   /* dedicated callback uses type="password" */
        'claude-chat-settings',
        'claude_chat_settings_section',
        array('label_for' => 'claude_chat_api_key')
    );

    add_settings_field(
        'claude_chat_model',
        'Model',
        'fClaudeChatDropdownCallback',
        'claude-chat-settings',
        'claude_chat_settings_section',
        array('label_for' => 'claude_chat_model')
    );

    add_settings_field(
        'claude_chat_temperature',
        'Temperature',
        'fClaudeChatNumberFieldCallback',
        'claude-chat-settings',
        'claude_chat_settings_section',
        array(
            'label_for' => 'claude_chat_temperature',
            'description' => 'Range: 0 to 1',
            'min' => 0,
            'max' => 1,
            'step' => 0.1,
        )
    );

    add_settings_field(
        'claude_chat_max_tokens',
        'Max Tokens',
        'fClaudeChatNumberFieldCallback',
        'claude-chat-settings',
        'claude_chat_settings_section',
        array(
            'label_for' => 'claude_chat_max_tokens',
            'description' => 'Range: 1 to 8096',
            'min' => 1,
            'max' => 8096,
        )
    );

    add_settings_field(
        'claude_chat_follow_links',
        'Follow Links',
        'fClaudeChatCheckboxFieldCallback',
        'claude-chat-settings',
        'claude_chat_settings_section',
        array(
            'label_for'   => 'claude_chat_follow_links',
            'description' => 'When checked, Claude may call the fetch_url tool to read URLs '
                           . 'named in the prompt or the user question. Each fetch times out after '
                           . cgClaudeChatFetchTimeOut . 's; the whole fetch loop stops after '
                           . cgClaudeChatResponseBudget . 's and answers with what it has. '
                           . 'Adds an API round trip per batch of fetches, so replies are slower and '
                           . 'cost more tokens.',
        )
    );

    add_settings_field(
        'claude_chat_prefetch_urls',
        'List of pre-fetch URLs',
        'fClaudeChatTextareaFieldCallback',
        'claude-chat-settings',
        'claude_chat_settings_section',
        array(
            'label_for'   => 'claude_chat_prefetch_urls',
            'description' => 'Optional. One URL per line, max ' . cgClaudeChatMaxPreFetchUrls . '. '
                           . 'These are always fetched and added to the system prompt, whether or not '
                           . 'Follow Links is checked. Content is cached for '
                           . intval( cgClaudeChatPreFetchTtl / 60 ) . ' minutes and truncated to '
                           . number_format( cgClaudeChatMaxFetchChars ) . ' characters per page. '
                           . 'Leave blank to disable.',
        )
    );

    add_settings_field(
        'claude_chat_prefix_prompt',
        'Prefix Prompt',
        'fClaudeChatTextareaFieldCallback',
        'claude-chat-settings',
        'claude_chat_settings_section',
        array(
            'label_for'   => 'claude_chat_prefix_prompt',
            'description' => 'Optional. Sent as the system prompt on every request, keeping it separate from user input. Uses cache_control to save costs. Leave blank to disable. Max ' . number_format( cgClaudeChatMaxPrefixPrompt ) . ' bytes.',
        )
    );
}
add_action('admin_init', 'fClaudeChatSettingsInit');

/* Field render callbacks */
function fClaudeChatSettingsSection($args) {
    echo '<p>Version: VERSION</p>';
    echo '<p>Click <a href="https://github.com/TurtleEngr/WP-Claude-Interface/blob/main/README.md" target="_blank">HERE</a> for help.</p>';
    echo '<p>Enter your Claude API settings below:</p>';
}

/* Render the API key as a password field so it is masked in the
 * browser.
 */
function fClaudeChatApiKeyFieldCallback($args) {
    $option = get_option($args['label_for']);
    echo '<input type="password" id="'  . esc_attr($args['label_for'])
        . '" name="'                     . esc_attr($args['label_for'])
        . '" value="'                    . esc_attr($option)
        . '" class="regular-text"'
        . ' autocomplete="new-password">';
    if ( ! empty($args['description'])) {
        echo '<p class="description">' . wp_kses($args['description'], array('code' => array())) . '</p>';
    }
}

function fClaudeChatNumberFieldCallback($args) {
    $option = get_option($args['label_for']);
    echo '<input type="number" id="' . esc_attr($args['label_for'])
        . '" name="'                  . esc_attr($args['label_for'])
        . '" value="'                 . esc_attr($option)
        . '" class="regular-text"'
        . ' min="'                    . esc_attr($args['min'])
        . '" max="'                   . esc_attr($args['max'])
        . '" step="'                  . (isset($args['step']) ? esc_attr($args['step']) : '1')
        . '">';
    if ( ! empty($args['description'])) {
        echo '<p class="description">' . wp_kses($args['description'], array('code' => array())) . '</p>';
    }
}


/*
 * Checkbox field.
 *
 * The hidden input is required: an unchecked checkbox submits nothing, so
 * without it the Settings API never sees the key, the option keeps its old
 *  value, and the box would appear impossible to turn off.
 */
function fClaudeChatCheckboxFieldCallback($args) {
    $option = get_option($args['label_for'], '');
    echo '<input type="hidden" name="' . esc_attr($args['label_for']) . '" value="0">';
    echo '<input type="checkbox" id="' . esc_attr($args['label_for'])
        . '" name="'                    . esc_attr($args['label_for'])
        . '" value="1" '                . checked('1', $option, false)
        . '>';
    if ( ! empty($args['description'])) {
        echo '<p class="description">' . wp_kses($args['description'], array('code' => array())) . '</p>';
    }
}

function fClaudeChatDropdownCallback($args) {
    $selected_model = get_option($args['label_for']);
    echo '<select id="'   . esc_attr($args['label_for'])
        . '" name="'       . esc_attr($args['label_for'])
        . '" class="regular-text">';
    foreach (cgClaudeChatModels as $model_key => $model_name) {
        $selected = ($selected_model == $model_key) ? 'selected="selected"' : '';
        echo '<option value="' . esc_attr($model_key) . '" ' . $selected . '>'
            . esc_html($model_name) . '</option>';
    }
    echo '</select>';
    if ( ! empty($args['description'])) {
        echo '<p class="description">' . wp_kses($args['description'], array('code' => array())) . '</p>';
    }
}

function fClaudeChatTextareaFieldCallback($args) {
    $option = get_option($args['label_for'], '');
    echo '<textarea id="'   . esc_attr($args['label_for'])
        . '" name="'         . esc_attr($args['label_for'])
        . '" rows="6" cols="60" class="large-text code">'
        . esc_textarea($option)
        . '</textarea>';
    if ( ! empty($args['description'])) {
        echo '<p class="description">' . wp_kses($args['description'], array('code' => array())) . '</p>';
    }
}
