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
