<div class="form-group custom-conv-fields{{ $errors->has('users') ? ' has-error' : '' }}">
    <label for="name" class="col-sm-2 control-label">{{ __('Users and teams') }}</label>

    <div class="col-sm-9">

        <select class="form-control parsley-exclude draft-changer" name="users[]" id="users" multiple="multiple" required autofocus/>
        @if (!empty($users))
            @foreach ($users as $user_id => $user_name)
                <option value="{{ $user_id }}" selected="selected">{{ $user_name }}</option>
                @endforeach
                @endif
                </select>

                @include('partials/field_error', ['field'=>'users'])
    </div>
</div>

<div class="form-group custom-conv-fields">
    <label for="is_public" class="col-sm-2 control-label">{{ __('Public conversation') }}</label>

    <div class="col-sm-9">
        <div class="checkbox" style="padding:3px 0 0">
            <label>
                <input type="checkbox" name="internal_conversation_is_public" id="internal_conversation_is_public" value="1" class="draft-changer">
                {{ __('Make this conversation visible to all users with mailbox access') }}
            </label>
        </div>
    </div>
</div>
