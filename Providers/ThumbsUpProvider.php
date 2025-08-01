<?php

namespace Modules\InternalConversations\Providers;

use App\Conversation;
use App\Subscription;
use App\Thread;
use App\User;

class ThumbsUpProvider {
    private static self $instance;

    private function __construct() {
        $this->hooks();
    }

    public static function instance(): self {
        if ( ! isset( self::$instance ) ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function hooks() {
        \Eventy::addFilter( 'middleware.web.custom_handle.response', [ $this, 'handleThumbsUpAction' ], 20, 2 );
        \Eventy::addAction( 'thread.info.prepend', [ $this, 'addThumbsUpButton' ] );
    }

    public function handleThumbsUpAction( $response, $request ) {
        if ( $request->route()->getName() !== 'conversations.view' || ! $request->isMethod( 'GET' ) ) {
            return $response;
        }

        $conversation_id = $request->route( 'id' );

        if ( empty( $request->ic_action ) || empty( $request->ic_thread_id ) || empty( $conversation_id ) ) {
            return $response;
        }

        /** @var Conversation $conversation */
        $conversation = Conversation::find( $conversation_id );
        if ( ! $conversation ) {
            return $response;
        }

        /** @var Thread $thread */
        $thread = Thread::find( $request->ic_thread_id );

        if ( ! $thread || (int) $thread->conversation_id !== (int) $conversation_id ) {
            return $response;
        }

        $userId = $request->user()->id;

        if ( $request->ic_action === 'execute' ) {
            $meta            = $thread->getMeta( 'ic.thumbs_up', [] );
            $meta[ $userId ] = now()->getTimestamp();
            $thread->setMeta( 'ic.thumbs_up', $meta );

            $thread->save();
            
            // Trigger action for thumbs up
            \Eventy::action('internal_conversation.thumbs_up', $conversation, $thread, $userId);
        } else if ( $request->ic_action === 'undo' ) {
            $meta = $thread->getMeta( 'ic.thumbs_up', [] );
            if ( isset( $meta[ $userId ] ) ) {
                unset( $meta[ $userId ] );
            }
            $thread->setMeta( 'ic.thumbs_up', $meta );

            $thread->save();
        }

        // Reload the page.
        $url_data = $request->all();

        unset( $url_data['ic_action'] );
        unset( $url_data['ic_thread_id'] );

        $url_data['id'] = $conversation_id;

        return redirect()->route( 'conversations.view', $url_data );
    }

    public function addThumbsUpButton( Thread $thread ) {
        if ( $thread->type !== Thread::TYPE_NOTE ) {
            return;
        }
        /** @var Conversation $conversation */
        $conversation = $thread->conversation()->first();
        if ( ! $conversation ) {
            return;
        }

        if ( ! $conversation->isCustom() ) {
            return;
        }

        $url_data = request()->all();

        $url_data['ic_thread_id'] = $thread->id;

        $userId = request()->user()->id;

        $meta           = $thread->getMeta( 'ic.thumbs_up', [] );
        $userHasLiked   = isset( $meta[ $userId ] );
        $amountOfLikes  = count( $meta );
        $usersThatLiked = array_keys( $meta );
        $names          = [];
        foreach ( $usersThatLiked as $userId ) {
            /** @var User $user */
            $user = User::find( $userId );
            if ( $user ) {
                $names[] = $user->getFullName();
            }
        }

        if ( $userHasLiked ) {
            $url_data['ic_action'] = 'undo';
            ?>
            <a href="?<?php echo http_build_query( $url_data ) ?>" class="thread-info-icon link-active" data-toggle="tooltip" title="<?php echo __( 'Liked by: ' ) . implode( ', ', $names ) ?>"><?php echo $amountOfLikes; ?> <i
                    class="glyphicon glyphicon-thumbs-up"></i></a>
            <?php
        } else {
            $url_data['ic_action'] = 'execute';
            $text                  = __( 'Like' );
            if ( $amountOfLikes > 0 ) {
                $text = __( 'Liked by: ' ) . implode( ', ', $names );
            }
            ?>
            <a href="?<?php echo http_build_query( $url_data ) ?>" class="thread-info-icon" data-toggle="tooltip" title="<?php echo $text ?>"><?php echo $amountOfLikes; ?> <i class="glyphicon glyphicon-thumbs-up"></i></a>
            <?php
        }
    }
}
