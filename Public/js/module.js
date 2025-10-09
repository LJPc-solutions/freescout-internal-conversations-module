console.log('InternalConversations module loaded')

function switchToNewPhoneInternalConversation() {
    $('#email-conv-switch').removeClass('active');
    $('#phone-conv-switch').removeClass('active');
    $('#internal-conv-switch').addClass('active');
    $('.email-conv-fields').hide();
    $('.phone-conv-fields').hide();
    $('.custom-conv-fields').show();

    $('#field-to').hide();
    $('#name').addClass('parsley-exclude');
    $('#to').addClass('parsley-exclude');

    $('.conv-block:first').addClass('conv-note-block').addClass('conv-phone-block');

    $('#form-create :input[name="is_note"]:first').val(1);
    $('#form-create :input[name="is_phone"]:first').val(1);
    $('#form-create :input[name="type"]:first').val(Vars.conv_type_custom);

    initUserFieldSelector({
        maximumSelectionLength: 0,
        use_id: true
    }, $('#users:not(.select2-hidden-accessible)'));
}

function initUserFieldSelector(custom_options, selector) {
    var options = {
        editable: true,
        use_id: false,
        containerCssClass: 'select2-user',
        //selectOnClose: true,
        // For hidden inputs
        width: '100%'
    };

    if (typeof (custom_options) == "undefined") {
        custom_options = {};
    }

    $.extend(options, custom_options);

    var result = initUserSelector(selector, options);
    result = fsApplyFilter('conversation.user_selector', result, {selector: selector});

    if (options.editable) {
        result.on('select2:closing', function (e) {
            var params = e.params;
            var select = $(e.target);

            var value = select.next('.select2:first').children().find('.select2-search__field:first').val();
            value = value.trim();
            if (!value) {
                return;
            }

            // Don't allow to create a tag if there is no @ symbol
            if (typeof (custom_options.allow_non_emails) == "undefined") {
                if (!/^.+@.+$/.test(value)) {
                    // Return null to disable tag creation
                    return null;
                }
            }

            // Don't select an item if the close event was triggered from a select or
            // unselect event
            if (params && params.args && params.args.originalSelect2Event != null) {
                var event = params.args.originalSelect2Event;

                if (event._type === 'select' || event._type === 'unselect') {
                    return;
                }
            }

            var data = select.select2('data');

            // Check if select already has such option
            for (i in data) {
                if (data[i].id == value) {
                    return;
                }
            }

            addSelect2Option(select, {
                id: value,
                text: value,
                selected: true
            });
        });
    }

    fsDoAction('conversation.user_selector_initialized', {selector: selector});

    return result;
}


function initUserSelector(input, custom_options) {
    var use_id = true;

    if (typeof (custom_options.use_id) != "undefined") {
        use_id = custom_options.use_id;
        if (!use_id) {
            use_id = null;
        }
    }

    var options = {
        ajax: {
            url: laroute.route('internal_conversations.users.ajax_search'),
            dataType: 'json',
            delay: 250,
            cache: true,
            data: function (params) {
                return {
                    q: params.term,
                    exclude_email: input.attr('data-customer_email'),
                    use_id: use_id,
                    page: params.page,
                    mailbox_id: $('body').attr('data-mailbox_id')
                };
            }
        },
        containerCssClass: "select2-multi-container", // select2-with-loader
        dropdownCssClass: "select2-multi-dropdown",
        minimumInputLength: 2
    };
    // When placeholder is set on invisible input, it breaks input
    if (input.length == 1 && input.is(':visible')) {
        options.placeholder = input.attr('placeholder');
    }
    if (typeof (custom_options.editable) != "undefined" && custom_options.editable) {
        var token_separators = [",", ", ", " "];
        if (typeof (custom_options.maximumSelectionLength) != "undefined" && custom_options.maximumSelectionLength == 1) {
            token_separators = [];
        }
        $.extend(options, {
            multiple: true,
            tags: true,
            tokenSeparators: token_separators,
            createTag: function (params) {
                return null;
            }.bind(input),
            templateResult: function (data) {
                var $result = $("<span></span>");

                $result.text(data.text);

                return $result;
            }
        });
    }
    if (typeof (custom_options) != 'undefined') {
        $.extend(options, custom_options);
    }

    return input.select2(options);
}

$(document).ready(function () {
    $('#internal-conv-switch').click(function () {
        switchToNewPhoneInternalConversation();
    });
});


$(document).ready(function () {
    $('.ic-user-item').click(function (e) {
        e.preventDefault();

        const buttons = $(this);
        const isSubscribed = $(this).hasClass('ic-user-subscribed');
        const userId = $(this).data('user_id');

        let route = 'internal_conversations.users.add';
        if (isSubscribed) {
            route = 'internal_conversations.users.remove';
        }

        fsAjax({
                conversation_id: getGlobalAttr('conversation_id'),
                user_id: userId
            },
            laroute.route(route),
            function (response) {
                if (isAjaxSuccess(response)) {
                    if (!isSubscribed) {
                        buttons.addClass('ic-user-subscribed');
                        buttons.find('.glyphicon').removeClass('glyphicon-eye-close').addClass('glyphicon-eye-open');
                    } else {
                        buttons.removeClass('ic-user-subscribed');
                        buttons.find('.glyphicon').removeClass('glyphicon-eye-open').addClass('glyphicon-eye-close');
                    }
                } else {
                    showAjaxResult(response);
                }
            }, true
        );
    });

    $('#add-everyone').click(function (e) {
        e.preventDefault();

        if (!confirm('Are you sure you want to add everyone to this conversation?')) return;
        fsAjax({
                conversation_id: getGlobalAttr('conversation_id'),
            },
            laroute.route('internal_conversations.users.add_everyone'),
            function (response) {
                if (isAjaxSuccess(response)) {
                    $('.ic-user-item').addClass('ic-user-subscribed');
                    $('.ic-user-item').find('.glyphicon').removeClass('glyphicon-eye-close').addClass('glyphicon-eye-open');
                } else {
                    showAjaxResult(response);
                }
            }, true
        );
    });

    $('#remove-everyone').click(function (e) {
        e.preventDefault();

        if (!confirm('Are you sure you want to remove everyone from this conversation?')) return;
        fsAjax({
                conversation_id: getGlobalAttr('conversation_id'),
            },
            laroute.route('internal_conversations.users.remove_everyone'),
            function (response) {
                if (isAjaxSuccess(response)) {
                    $('.ic-user-item').removeClass('ic-user-subscribed');
                    $('.ic-user-item').find('.glyphicon').removeClass('glyphicon-eye-open').addClass('glyphicon-eye-close');

                    //keep the current user subscribed
                    $('.ic-user-item[data-user_id="' + getGlobalAttr('auth_user_id') + '"]').addClass('ic-user-subscribed');
                    $('.ic-user-item[data-user_id="' + getGlobalAttr('auth_user_id') + '"]').find('.glyphicon').removeClass('glyphicon-eye-close').addClass('glyphicon-eye-open');
                } else {
                    showAjaxResult(response);
                }
            }, true
        );
    });
    
    // Handle public conversation toggle
    $('#ic-public-toggle').change(function(e) {
        var checkbox = $(this);
        var conversationId = checkbox.data('conversation-id');
        var isPublic = checkbox.is(':checked');
        
        fsAjax({
                conversation_id: conversationId,
                is_public: isPublic
            },
            laroute.route('internal_conversations.toggle_public'),
            function (response) {
                if (isAjaxSuccess(response)) {
                    showFloatingAlert('success', isPublic ? 'Conversation is now public' : 'Conversation is now private');
                } else {
                    // Revert checkbox state on error
                    checkbox.prop('checked', !isPublic);
                    showAjaxResult(response);
                }
            }, true
        );
    });
});
