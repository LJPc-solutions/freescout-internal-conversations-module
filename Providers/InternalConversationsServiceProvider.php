<?php

namespace Modules\InternalConversations\Providers;

use App\Conversation;
use App\Folder;
use App\Mailbox;
use App\Subscription;
use App\Thread;
use App\User;
use Illuminate\Database\Eloquent\Factory;
use Illuminate\Support\ServiceProvider;
use Modules\Mentions\Providers\MentionsServiceProvider;
use Modules\Teams\Providers\TeamsServiceProvider as Teams;

class InternalConversationsServiceProvider extends ServiceProvider {
    const EVENT_IC_NEW_MESSAGE = 100;
    const EVENT_IC_NEW_REPLY = 101;

    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    /**
     * Boot the application events.
     *
     * @return void
     */
    public function boot() {
        $this->registerConfig();
        $this->registerViews();
        $this->registerFactories();
        $this->loadMigrationsFrom( __DIR__ . '/../Database/Migrations' );
        $this->hooks();
    }

    /**
     * Module hooks.
     */
    public function hooks() {
        \Eventy::addAction( 'conversation.new.conv_switch_buttons', function ( $mailbox ) {
            echo \View::make( 'internalconversations::partials/conv-switch-button', [] )->render();
        } );

        \Eventy::addAction( 'conversation.create_form.before_subject', function ( $conversation, $mailbox, $thread ) {
            echo \View::make( 'internalconversations::partials/create-form-fields', [
                'conversation' => $conversation,
                'mailbox'      => $mailbox,
                'thread'       => $thread,
            ] )->render();
        }, 10, 3 );

        \Eventy::addFilter( 'conversation.custom.identifier', function ( $styles ) {
            return __( 'Internal conversation' );
        } );

        \Eventy::addFilter( 'conversation.reply_button.enabled', function ( $enabled, $conversation ) {
            if ( ! ( $conversation instanceof Conversation ) ) {
                return $enabled;
            }

            return $conversation->isCustom() ? false : $enabled;
        }, 10, 2 );

        // Add module's CSS file to the application layout.
        \Eventy::addFilter( 'stylesheets', function ( $styles ) {
            $styles[] = \Module::getPublicPath( 'internalconversations' ) . '/css/module.css';

            return $styles;
        } );

        // Add module's JS file to the application layout.
        \Eventy::addFilter( 'javascripts', function ( $javascripts ) {
            $javascripts[] = \Module::getPublicPath( 'internalconversations' ) . '/js/laroute.js';
            $javascripts[] = \Module::getPublicPath( 'internalconversations' ) . '/js/module.js';

            return $javascripts;
        } );

        \Eventy::addAction( 'conversation.send_reply_save', function ( $conversation, $request ) {
            if ( ! $conversation->isCustom() ) {
                return;
            }

            Subscription::registerEvent( self::EVENT_IC_NEW_MESSAGE, $conversation, auth()->user()->id );


            /**
             * @var Conversation $conversation
             */
            $connectedUsers = $conversation->getMeta( 'internal_conversations.users', [] );

            $users = $request->all()['users'] ?? [];
            foreach ( $users as $user ) {
                if ( ! in_array( (string) $user, $connectedUsers ) ) {
                    $connectedUsers[] = (string) $user;
                }
            }

            if ( ! in_array( (string) auth()->user()->id, $connectedUsers ) ) {
                $connectedUsers[] = (string) auth()->user()->id;
            }
            $conversation->setMeta( 'internal_conversations.users', $connectedUsers );
            $conversation->save();
        }, 10, 2 );

        \Eventy::addAction( 'conversation.after_customer_sidebar', function ( $conversation ) {
            if ( ! $conversation->isCustom() ) {
                return;
            }
            /**
             * @var Conversation $conversation
             * @var Mailbox $mailbox
             */
            $mailbox = $conversation->mailbox()->first();

            $followers = [];

            $users = $mailbox->usersAssignable( true );

            $connectedUserIds = $conversation->getMeta( 'internal_conversations.users', [] );

            // Add followers first.
            foreach ( $users as $i => $user ) {
                foreach ( $connectedUserIds as $id ) {
                    if ( $id == $user->id ) {
                        $user->subscribed = true;
                        $followers[]      = $user;
                        $users->forget( $i );
                        break;
                    }
                }
            }
            foreach ( $users as $i => $user ) {
                if ( ! in_array( $user->id, $connectedUserIds ) ) {
                    $user->subscribed = false;
                    $followers[]      = $user;
                }
            }

            echo \View::make( 'internalconversations::partials/sidebar', [
                'conversation' => $conversation,
                'followers'    => $followers,
            ] )->render();
        }, 10, 1 );

        \Eventy::addFilter( 'folder.conversations_query', function ( $query, $folder, $user_id ) {
            $customType = Conversation::TYPE_CUSTOM;

            $teamIds = [];
            if ( class_exists( Teams::class ) ) {
                $teamsForUser = User::whereRaw( "last_name = '" . Teams::TEAM_USER_LAST_NAME . "' and email like '" . Teams::TEAM_USER_EMAIL_PREFIX . "%' and JSON_CONTAINS(emails,'\"$user_id\"','$')" )->get();
                $teamIds      = $teamsForUser->pluck( 'id' )->toArray();
            }
            $rawSqlBuilder = "JSON_CONTAINS(meta, '\"$user_id\"', '$.\"internal_conversations.users\"')";

            foreach ( $teamIds as $teamId ) {
                $rawSqlBuilder .= " OR JSON_CONTAINS(meta, '\"$teamId\"', '$.\"internal_conversations.users\"')";
            }

            if ( $folder->type == Folder::TYPE_MINE ) {
                $query_conversations = Conversation::where( 'user_id', $user_id )
                                                   ->where( 'mailbox_id', $folder->mailbox_id )
                                                   ->whereIn( 'status', [ Conversation::STATUS_ACTIVE, Conversation::STATUS_PENDING ] )
                                                   ->where( 'state', Conversation::STATE_PUBLISHED );

            } else if ( $folder->type == Folder::TYPE_ASSIGNED ) {
                $query_conversations = $folder->conversations()
                                              ->where( 'user_id', '<>', $user_id )
                                              ->where( 'state', Conversation::STATE_PUBLISHED );

            } else if ( $folder->type == Folder::TYPE_STARRED ) {
                $starred_conversation_ids = Conversation::getUserStarredConversationIds( $folder->mailbox_id, $user_id );
                $query_conversations      = Conversation::whereIn( 'id', $starred_conversation_ids );
            } else if ( $folder->isIndirect() ) {
                $query_conversations = Conversation::select( 'conversations.*' )
                                                   ->join( 'conversation_folder', 'conversations.id', '=', 'conversation_folder.conversation_id' )
                                                   ->where( 'conversation_folder.folder_id', $folder->id );
                if ( $folder->type != Folder::TYPE_DRAFTS ) {
                    $query_conversations->where( 'state', Conversation::STATE_PUBLISHED );
                }
            } else if ( $folder->type == Folder::TYPE_DELETED ) {
                $query_conversations = $folder->conversations()->where( 'state', Conversation::STATE_DELETED );
            } else {
                $query_conversations = $folder->conversations()->where( 'state', Conversation::STATE_PUBLISHED );
            }


            $allowedConversations = $query_conversations->where( 'type', $customType )->whereRaw( $rawSqlBuilder )->pluck( 'id' )->toArray();

            if ( count( $allowedConversations ) > 0 ) {
                $query->whereRaw( "(type != $customType OR id IN (" . implode( ',', $allowedConversations ) . "))" );
            } else {
                $query->where( 'type', '!=', $customType );
            }

            return $query;
        }, 10, 3 );

        \Eventy::addAction( 'conversation.view.start', function ( Conversation $conversation, $request ) {
            if ( ! $conversation->isCustom() ) {
                return;
            }
            $user_id = auth()->user()->id;
            //redirect away if not allowed
            $teamIds = [];
            if ( class_exists( Teams::class ) ) {
                $teamsForUser = User::whereRaw( "last_name = '" . Teams::TEAM_USER_LAST_NAME . "' and email like '" . Teams::TEAM_USER_EMAIL_PREFIX . "%' and JSON_CONTAINS(emails,'\"$user_id\"','$')" )->get();
                $teamIds      = $teamsForUser->pluck( 'id' )->toArray();
            }

            $connectedUsers = $conversation->getMeta( 'internal_conversations.users', [] );
            if ( ! in_array( $user_id, $connectedUsers ) ) {
                $teamIsConnected = false;

                foreach ( $teamIds as $teamId ) {
                    if ( in_array( $teamId, $connectedUsers ) ) {
                        $teamIsConnected = true;
                        break;
                    }
                }

                if ( $teamIsConnected === false ) {
                    abort( 403 );
                }
            }
        }, 10, 2 );

        if ( class_exists( 'Modules\Mentions\Providers\MentionsServiceProvider' ) ) {
            \Eventy::addAction( 'conversation.note_added', function ( $conversation, $thread ) {
                if ( ! $conversation->isCustom() ) {
                    return;
                }
                $mentionedUsers = MentionsServiceProvider::getMentionedUsers( $thread->body );
                if ( count( $mentionedUsers ) === 0 ) {
                    return;
                }
                /** @var Conversation $conversation */
                $connectedUsers = $conversation->getMeta( 'internal_conversations.users', [] );
                foreach ( $mentionedUsers as $userId ) {
                    if ( in_array( (string) $userId, $connectedUsers ) ) {
                        continue;
                    }
                    $connectedUsers[] = (string) $userId;
                }
                $conversation->setMeta( 'internal_conversations.users', $connectedUsers );
                $conversation->save();
            }, 20, 2 );
        }

        \Eventy::addAction( 'notifications_table.general.append', function ( $vars ) {
            echo \View::make( 'internalconversations::partials/notifications_table', $vars )->render();
        }, 20, 1 );

        \Eventy::addAction( 'thread.before_save_from_request', function ( Thread $thread, $request ) {
            $conversationId = $thread->conversation_id;
            $conversation   = Conversation::find( $conversationId );
            if ( ! $conversation->isCustom() ) {
                return;
            }
            $thread->type = Thread::TYPE_NOTE;
        }, 20, 2 );

        ThumbsUpProvider::instance();

        $this->registerEvents();
    }

    private function registerEvents() {
        // Note added.
        \Eventy::addAction( 'conversation.note_added', function ( Conversation $conversation, $thread ) {
            if ( $conversation->isCustom() ) {
                Subscription::registerEvent( self::EVENT_IC_NEW_REPLY, $conversation, $thread->created_by_user_id );
            }
        }, 20, 2 );

        \Eventy::addFilter( 'subscription.events_by_type', function ( $events, $event_type, $thread ) {
            $connectedUsers = $thread->conversation->getMeta( 'internal_conversations.users', [] );

            if ( $event_type === Subscription::EVENT_TYPE_USER_ADDED_NOTE && $connectedUsers ) {
                $events[] = self::EVENT_IC_NEW_REPLY;
            }

            if ( $event_type === Subscription::EVENT_TYPE_NEW && $connectedUsers ) {
                $events[] = self::EVENT_IC_NEW_MESSAGE;
            }

            return $events;
        }, 20, 3 );

        \Eventy::addFilter( 'subscription.filter_out', function ( $filter_out, $subscription, $thread ) {
            if ( $subscription->event !== self::EVENT_IC_NEW_MESSAGE && $subscription->event !== self::EVENT_IC_NEW_REPLY ) {
                return $filter_out;
            }
            $connectedUsers = $thread->conversation->getMeta( 'internal_conversations.users', [] );

            if ( ! in_array( $subscription->user_id, $connectedUsers ) ) {
                return true;
            } else {
                return false;
            }
        }, 20, 3 );

        \Eventy::addFilter( 'subscription.is_related_to_user', function ( $is_related, $subscription, $thread ) {
            if ( $subscription->event !== self::EVENT_IC_NEW_MESSAGE && $subscription->event !== self::EVENT_IC_NEW_REPLY ) {
                return $is_related;
            }
            $connectedUsers = $thread->conversation->getMeta( 'internal_conversations.users', [] );

            if ( in_array( $subscription->user_id, $connectedUsers ) ) {
                return true;
            }

            return $is_related;
        }, 20, 3 );

        // Always show @mentions notification in the menu.
        \Eventy::addFilter( 'subscription.users_to_notify', function ( $users_to_notify, $event_type, $events, $thread ) {
            if ( in_array( self::EVENT_IC_NEW_MESSAGE, $events ) || in_array( self::EVENT_IC_NEW_REPLY, $events ) ) {
                $connectedUsers = $thread->conversation->getMeta( 'internal_conversations.users', [] );
                if ( count( $connectedUsers ) > 0 ) {
                    $users = User::whereIn( 'id', $connectedUsers )->get();
                    foreach ( $users as $user ) {
                        $users_to_notify[ Subscription::MEDIUM_MENU ][] = $user;
                        $users_to_notify[ Subscription::MEDIUM_MENU ]   = array_unique( $users_to_notify[ Subscription::MEDIUM_MENU ] );
                    }
                }
            }

            return $users_to_notify;
        }, 20, 4 );

        \Eventy::addFilter( 'conversation.type_name', function ( $type ) {
            if ( (int) $type === Conversation::TYPE_CUSTOM ) {
                return __( 'Internal conversation' );
            }

            return $type;
        }, 10, 1 );
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register() {
        $this->registerTranslations();
    }

    /**
     * Register config.
     *
     * @return void
     */
    protected function registerConfig() {
        $this->publishes( [
            __DIR__ . '/../Config/config.php' => config_path( 'internalconversations.php' ),
        ], 'config' );
        $this->mergeConfigFrom(
            __DIR__ . '/../Config/config.php', 'internalconversations'
        );
    }

    /**
     * Register views.
     *
     * @return void
     */
    public function registerViews() {
        $viewPath = resource_path( 'views/modules/internalconversations' );

        $sourcePath = __DIR__ . '/../Resources/views';

        $this->publishes( [
            $sourcePath => $viewPath,
        ], 'views' );

        $this->loadViewsFrom( array_merge( array_map( function ( $path ) {
            return $path . '/modules/internalconversations';
        }, \Config::get( 'view.paths' ) ), [ $sourcePath ] ), 'internalconversations' );
    }

    /**
     * Register translations.
     *
     * @return void
     */
    public function registerTranslations() {
        $this->loadJsonTranslationsFrom( __DIR__ . '/../Resources/lang' );
    }

    /**
     * Register an additional directory of factories.
     * @source https://github.com/sebastiaanluca/laravel-resource-flow/blob/develop/src/Modules/ModuleServiceProvider.php#L66
     */
    public function registerFactories() {
        if ( ! app()->environment( 'production' ) ) {
            app( Factory::class )->load( __DIR__ . '/../Database/factories' );
        }
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides() {
        return [];
    }
}
