// js/claude-chat.js
jQuery(document).ready(function($) {
    $('#claude-chat-submit').on('click', function() {
        var message = $('#claude-chat-input').val();
        if (message.trim() === '') return;

        $('#claude-chat-messages').append('<div class="user-message">' + message + '</div>');
        $('#claude-chat-input').val('');

        $.ajax({
            url: claudeChat.ajax_url,
            type: 'POST',
            data: {
                action: 'claude_chat',
                nonce: claudeChat.nonce,
                message: message
            },
            success: function(response) {
                if (response.success) {
                    $('#claude-chat-messages').append('<div class="claude-message">' + response.data + '</div>');
                } else {
                    $('#claude-chat-messages').append('<div class="error-message">Error: Unable to get a response</div>');
                }
            },
            error: function() {
                $('#claude-chat-messages').append('<div class="error-message">Error: Unable to send message</div>');
            }
        });
    });
});
