<?php

Route::group( [ 'middleware' => 'web', 'prefix' => \Helper::getSubdirectory(), 'namespace' => 'Modules\InternalConversations\Http\Controllers' ], function () {
    Route::get( '/internal-conversations/users/search', [ 'uses' => 'UsersController@ajaxSearch', 'laroute' => true ] )->name( 'internal_conversations.users.ajax_search' );

    Route::post( '/internal-conversations/users/add', [ 'uses' => 'UsersController@addToConversation', 'laroute' => true ] )->name( 'internal_conversations.users.add' );
    Route::post( '/internal-conversations/users/add_everyone', [ 'uses' => 'UsersController@addEveryoneToConversation', 'laroute' => true ] )->name( 'internal_conversations.users.add_everyone' );
    Route::post( '/internal-conversations/users/remove', [ 'uses' => 'UsersController@removeFromConversation', 'laroute' => true ] )->name( 'internal_conversations.users.remove' );
    Route::post( '/internal-conversations/users/remove_everyone', [ 'uses' => 'UsersController@removeEveryoneFromConversation', 'laroute' => true ] )->name( 'internal_conversations.users.remove_everyone' );
    Route::post( '/internal-conversations/toggle-public', [ 'uses' => 'UsersController@togglePublic', 'laroute' => true ] )->name( 'internal_conversations.toggle_public' );
} );
