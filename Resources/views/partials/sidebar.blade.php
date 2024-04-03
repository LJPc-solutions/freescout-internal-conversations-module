<div class="conv-customer-header"></div>
<div class="conv-customer-block conv-sidebar-block">

</div>
<div class="conv-sidebar-block users-block" data-auth_user_name="{{ Auth::user()->getFullName() }}">
    <div class="panel-group accordion accordion-empty">
        <div class="panel panel-default">
            <div class="panel-heading">
                <h4 class="panel-title">
                    <a data-toggle="collapse" href=".collapse-users">{{ __("Users and teams with access") }}
                        <b class="caret"></b>
                    </a>
                </h4>
            </div>
            <div class="collapse-users panel-collapse collapse in">
                <div class="panel-body">
                    <div class="sidebar-block-header2"><strong>{{ __("Users and teams") }}</strong> (<a data-toggle="collapse" href=".collapse-users">{{ __('close') }}</a>)</div>
                    <ul class="sidebar-block-list users-list">
                        @foreach ($followers as $follower)
                            <li>
                                <a href="#" data-user_id="{{ $follower->id }}"
                                   class="help-link ic-user-item ic-user-item-{{ $follower->id }} @if ($follower->subscribed) ic-user-subscribed @endif @if ($follower->subscribed && $follower->id != auth()->user()->id) ic-user-self @endif"><i
                                        class="glyphicon @if ($follower->subscribed) glyphicon-eye-open @else glyphicon-eye-close @endif"></i> {{ $follower->getFullName() }}</a>
                            </li>
                        @endforeach
                    </ul>
                    <div class="button-group">
                        <button class="btn btn-primary btn-xs" id="add-everyone">Add everyone</button>
                        <button class="btn btn-danger btn-xs" id="remove-everyone">Remove everyone</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

