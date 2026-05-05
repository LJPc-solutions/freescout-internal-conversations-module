<?php

Route::group( [ 'middleware' => 'web', 'prefix' => \Helper::getSubdirectory(), 'namespace' => 'Modules\InternalConversations\Http\Controllers' ], function () {
    Route::get( '/internal-conversations/users/search', [ 'uses' => 'UsersController@ajaxSearch', 'middleware' => [ 'auth', 'roles' ], 'roles' => [ 'admin', 'user' ], 'laroute' => true ] )->name( 'internal_conversations.users.ajax_search' );

    Route::post( '/internal-conversations/users/add', [ 'uses' => 'UsersController@addToConversation', 'middleware' => [ 'auth', 'roles' ], 'roles' => [ 'admin', 'user' ], 'laroute' => true ] )->name( 'internal_conversations.users.add' );
    Route::post( '/internal-conversations/users/add_everyone', [ 'uses' => 'UsersController@addEveryoneToConversation', 'middleware' => [ 'auth', 'roles' ], 'roles' => [ 'admin', 'user' ], 'laroute' => true ] )->name( 'internal_conversations.users.add_everyone' );
    Route::post( '/internal-conversations/users/remove', [ 'uses' => 'UsersController@removeFromConversation', 'middleware' => [ 'auth', 'roles' ], 'roles' => [ 'admin', 'user' ], 'laroute' => true ] )->name( 'internal_conversations.users.remove' );
    Route::post( '/internal-conversations/users/remove_everyone', [ 'uses' => 'UsersController@removeEveryoneFromConversation', 'middleware' => [ 'auth', 'roles' ], 'roles' => [ 'admin', 'user' ], 'laroute' => true ] )->name( 'internal_conversations.users.remove_everyone' );
    Route::post( '/internal-conversations/toggle-public', [ 'uses' => 'UsersController@togglePublic', 'middleware' => [ 'auth', 'roles' ], 'roles' => [ 'admin', 'user' ], 'laroute' => true ] )->name( 'internal_conversations.toggle_public' );
} );
