<td>
    {{ __('A new internal conversation is created and the user is connected to it') }}
</td>
<td class="subs-cb subscriptions-email"><input type="checkbox" @include('users/is_subscribed', ['medium' => App\Subscription::MEDIUM_EMAIL, 'event' => \InternalConversations::EVENT_IC_NEW_MESSAGE]) name="{{ $subscriptions_formname }}[{{ App\Subscription::MEDIUM_EMAIL }}][]" value="{{ \InternalConversations::EVENT_IC_NEW_MESSAGE }}"></td>
<td class="subs-cb subscriptions-browser"><input type="checkbox" @include('users/is_subscribed', ['medium' => App\Subscription::MEDIUM_BROWSER, 'event' => \InternalConversations::EVENT_IC_NEW_MESSAGE]) name="{{ $subscriptions_formname }}[{{ App\Subscription::MEDIUM_BROWSER }}][]" value="{{ \InternalConversations::EVENT_IC_NEW_MESSAGE }}"></td>
<td class="subs-cb subscriptions-mobile"><input type="checkbox" @include('users/is_subscribed', ['medium' => App\Subscription::MEDIUM_MOBILE, 'event' => \InternalConversations::EVENT_IC_NEW_MESSAGE]) name="{{ $subscriptions_formname }}[{{ App\Subscription::MEDIUM_MOBILE }}][]" @if (!$mobile_available) disabled="disabled" @endif value="{{ \InternalConversations::EVENT_IC_NEW_MESSAGE }}"></td>
@action('notifications_table.td', \InternalConversations::EVENT_IC_NEW_MESSAGE, $subscriptions_formname, $subscriptions)
</tr>

<td>
    {{ __('A new reply is added to an internal conversation and the user is connected to it') }}
</td>
<td class="subs-cb subscriptions-email"><input type="checkbox" @include('users/is_subscribed', ['medium' => App\Subscription::MEDIUM_EMAIL, 'event' => \InternalConversations::EVENT_IC_NEW_REPLY]) name="{{ $subscriptions_formname }}[{{ App\Subscription::MEDIUM_EMAIL }}][]" value="{{ \InternalConversations::EVENT_IC_NEW_REPLY }}"></td>
<td class="subs-cb subscriptions-browser"><input type="checkbox" @include('users/is_subscribed', ['medium' => App\Subscription::MEDIUM_BROWSER, 'event' => \InternalConversations::EVENT_IC_NEW_REPLY]) name="{{ $subscriptions_formname }}[{{ App\Subscription::MEDIUM_BROWSER }}][]" value="{{ \InternalConversations::EVENT_IC_NEW_REPLY }}"></td>
<td class="subs-cb subscriptions-mobile"><input type="checkbox" @include('users/is_subscribed', ['medium' => App\Subscription::MEDIUM_MOBILE, 'event' => \InternalConversations::EVENT_IC_NEW_REPLY]) name="{{ $subscriptions_formname }}[{{ App\Subscription::MEDIUM_MOBILE }}][]" @if (!$mobile_available) disabled="disabled" @endif value="{{ \InternalConversations::EVENT_IC_NEW_REPLY }}"></td>
@action('notifications_table.td', \InternalConversations::EVENT_IC_NEW_REPLY, $subscriptions_formname, $subscriptions)
</tr>
