<?php

namespace Modules\InternalConversations\Http\Controllers;

use App\Conversation;
use App\Mailbox;
use App\User;
use Helper;
use Illuminate\Routing\Controller;
use Modules\Teams\Providers\TeamsServiceProvider as Teams;
use Response;

class UsersController extends Controller {
    public function ajaxSearch() {
        $query    = strtoupper( request()->input( 'q' ) );
        $response = [
            'results'    => [],
            'pagination' => [ 'more' => false ],
        ];

        $mailboxId = request()->input( 'mailbox_id' );
        $mailbox   = Mailbox::find( $mailboxId );
        if ( $mailbox === null ) {
            return Response::json( $response );
        }


        $allTeams = [];
        if ( class_exists( Teams::class ) ) {
            //Teams are available
            $allTeams = Teams::getTeams( true );
        }

        $allUsers = User::where( 'status', User::STATUS_ACTIVE )
                        ->remember( Helper::cacheTime( true ) )
                        ->get();

        /** @var User $team */
        foreach ( $allTeams as $team ) {
            if ( ! str_contains( strtoupper( $team->getFirstName() ), $query ) ) {
                continue;
            }
            if ( ! $mailbox->userHasAccess( $team->id ) ) {
                continue;
            }

            $response['results'][] = [
                'id'   => $team->id,
                'text' => 'Team: ' . $team->getFirstName(),
            ];
        }

        /** @var User $user */
        foreach ( $allUsers as $user ) {
            if ( ! str_contains( strtoupper( $user->getFullName() ), $query ) && ! str_contains( strtoupper( $user->email ), $query ) ) {
                continue;
            }
            if ( ! $mailbox->userHasAccess( $user->id ) ) {
                continue;
            }
            $response['results'][] = [
                'id'   => $user->id,
                'text' => $user->getFullName(),
            ];
        }

        return Response::json( $response );
    }

    public function addToConversation() {
        $conversationId = request()->input( 'conversation_id' );
        $userId         = request()->input( 'user_id' );

        $user = User::find( $userId );
        if ( $user === null ) {
            return Response::json( [ 'status' => 'error', 'message' => 'User not found' ] );
        }

        /** @var Conversation $conversation */
        $conversation = Conversation::find( $conversationId );

        $mailbox = $conversation->mailbox()->first();
        if ( $mailbox->userHasAccess( $userId ) === false ) {
            return Response::json( [ 'status' => 'error', 'message' => 'User not allowed' ] );
        }

        $connectedUsers = $conversation->getMeta( 'internal_conversations.users', [] );
        if ( in_array( (string) $userId, $connectedUsers ) ) {
            return Response::json( [ 'status' => 'success' ] );
        }
        $connectedUsers[] = (string) $userId;
        $conversation->setMeta( 'internal_conversations.users', $connectedUsers );
        $conversation->save();

        return Response::json( [ 'status' => 'success', 'connected_users' => $connectedUsers ] );
    }

    public function addEveryoneToConversation() {
        $conversationId = request()->input( 'conversation_id' );

        /** @var Conversation $conversation */
        $conversation = Conversation::find( $conversationId );

        $mailbox = $conversation->mailbox()->first();
        $users   = $mailbox->usersAssignable();


        $connectedUsers = $conversation->getMeta( 'internal_conversations.users', [] );
        foreach ( $users as $user ) {
            if ( in_array( (string) $user->id, $connectedUsers ) ) {
                continue;
            }
            $connectedUsers[] = (string) $user->id;
        }
        $conversation->setMeta( 'internal_conversations.users', $connectedUsers );
        $conversation->save();

        return Response::json( [ 'status' => 'success', 'connected_users' => $connectedUsers ] );
    }

    public function removeFromConversation() {
        $conversationId = request()->input( 'conversation_id' );
        $userId         = request()->input( 'user_id' );

        $user = User::find( $userId );
        if ( $user === null ) {
            return Response::json( [ 'status' => 'error', 'message' => 'User not found' ] );
        }

        /** @var Conversation $conversation */
        $conversation   = Conversation::find( $conversationId );
        $connectedUsers = $conversation->getMeta( 'internal_conversations.users', [] );
        foreach ( $connectedUsers as $key => $connectedUserId ) {
            if ( $connectedUserId === (string) $userId ) {
                unset( $connectedUsers[ $key ] );
            }
        }
        $connectedUsers = array_values( $connectedUsers );
        $conversation->setMeta( 'internal_conversations.users', $connectedUsers );
        $conversation->save();

        return Response::json( [ 'status' => 'success', 'connected_users' => $connectedUsers ] );
    }

    public function removeEveryoneFromConversation() {
        $conversationId = request()->input( 'conversation_id' );

        /** @var Conversation $conversation */
        $conversation = Conversation::find( $conversationId );

        $connectedUsers = $conversation->getMeta( 'internal_conversations.users', [] );
        $connectedUsers = [];
        //add my id
        $connectedUsers[] = (string) auth()->user()->id;
        $conversation->setMeta( 'internal_conversations.users', $connectedUsers );
        $conversation->save();

        return Response::json( [ 'status' => 'success', 'connected_users' => $connectedUsers ] );
    }

    public function togglePublic() {
        $conversationId = request()->input( 'conversation_id' );
        $isPublic = request()->input( 'is_public' );

        /** @var Conversation $conversation */
        $conversation = Conversation::find( $conversationId );
        
        if ( $conversation === null || ! $conversation->isCustom() ) {
            return Response::json( [ 'status' => 'error', 'message' => 'Conversation not found' ] );
        }
        
        // Check if user has permission to modify this conversation
        $userId = auth()->user()->id;
        $connectedUsers = $conversation->getMeta( 'internal_conversations.users', [] );
        
        if ( ! in_array( (string) $userId, $connectedUsers ) ) {
            return Response::json( [ 'status' => 'error', 'message' => 'Permission denied' ] );
        }
        
        // Update public status
        $conversation->setMeta( 'internal_conversations.is_public', $isPublic );
        $conversation->save();
        
        return Response::json( [ 'status' => 'success', 'is_public' => $isPublic ] );
    }

}
