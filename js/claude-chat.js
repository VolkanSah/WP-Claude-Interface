jQuery(document).ready(function($) {
    $('#claude-chat-submit').on('click', function() {
        var userInput = $('#claude-chat-input').val();
        if (userInput.trim() === '') {
            console.log('Empty message, nothing sent.');
            return;
        }

        console.log('Message sent.');

        var $messages = $('#claude-chat-messages');

        // FIX: Use .text() to set the user message so any HTML special
        // characters in the input are treated as plain text, preventing XSS.
        var $userMsg = $('<div class="user-message"></div>').text(userInput);
        $messages.append($userMsg);
        // Scroll down so the just-added user message is visible.
        $messages.scrollTop($messages[0].scrollHeight);
        $('#claude-chat-input').val('');

        $.ajax({
            url: claudeChat.ajax_url,
            type: 'POST',
            data: {
                action:  'claude_chat',
                nonce:   claudeChat.nonce,
                message: userInput,
            },
            success: function(response) {
                console.log('AJAX-Success:', response);
                if (response.success) {
                    var $claudeMsg = $('<div class="claude-message"></div>').html(response.data);
                    $messages.append($claudeMsg);
                    // Scroll to the user's message position, then up 10 lines.
                    var lineHeight = parseFloat($messages.css('line-height')) || 24;
                    var userMsgTop = $userMsg[0].offsetTop;
                    $messages.scrollTop(userMsgTop - lineHeight * 10);
                } else {
                    console.log('Response error:', response);
                    $messages.append('<div class="error-message">Error: Unable to get a response</div>');
                }
            },
            error: function(xhr, status, error) {
                console.log('AJAX-Error:', error);
                console.log('AJAX-Status:', status);
                console.log('AJAX-XHR:', xhr);
                $messages.append('<div class="error-message">Error: Unable to send message</div>');
            }
        });
    });
});
