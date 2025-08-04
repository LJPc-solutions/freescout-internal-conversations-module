<?php

namespace Modules\InternalConversations\Providers;

use App\Conversation;
use App\ConversationFolder;
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
		const EVENT_IC_THUMBS_UP = 102;

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

						$mailbox = $conversation->mailbox()->first();

						Subscription::registerEvent( self::EVENT_IC_NEW_MESSAGE, $conversation, auth()->user()->id );

						/**
						 * @var Conversation $conversation
						 */
						$connectedUsers = $conversation->getMeta( 'internal_conversations.users', [] );

						$users = $request->all()['users'] ?? [];
						foreach ( $users as $user ) {
								if ( ! $mailbox->userHasAccess( $user ) ) {
										continue;
								}
								if ( ! in_array( (string) $user, $connectedUsers ) ) {
										$connectedUsers[] = (string) $user;
								}
						}

						if ( ! in_array( (string) auth()->user()->id, $connectedUsers ) ) {
								$connectedUsers[] = (string) auth()->user()->id;
						}
						
						// Check for mentioned users in the message body
						if ( class_exists( 'Modules\Mentions\Providers\MentionsServiceProvider' ) ) {
								$body = $request->get('body', '');
								if ( $body ) {
										$mentionedUsers = MentionsServiceProvider::getMentionedUsers( $body );
										foreach ( $mentionedUsers as $userId ) {
												// Check if user has mailbox access
												if ( $mailbox->userHasAccess( $userId ) && ! in_array( (string) $userId, $connectedUsers ) ) {
														$connectedUsers[] = (string) $userId;
												}
										}
								}
						}
						
						$conversation->setMeta( 'internal_conversations.users', $connectedUsers );
						
						// Handle public conversation setting
						$isPublic = $request->has('internal_conversation_is_public');
						$conversation->setMeta( 'internal_conversations.is_public', $isPublic );
						
						$conversation->last_reply_at   = date( 'Y-m-d H:i:s' );
						$conversation->last_reply_from = Conversation::PERSON_USER;
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
						$isPublic = $conversation->getMeta( 'internal_conversations.is_public', false );
						
						// Check if current user has edit permission
						$currentUserId = auth()->user()->id;
						$canEditPublic = in_array( (string) $currentUserId, $connectedUserIds );

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
								'canEditPublic' => $canEditPublic,
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

						if ( $folder->type == Folder::TYPE_DRAFTS ) {
								$rawSqlBuilder .= ' OR (`conversations`.created_by_user_id = ' . $user_id . ' AND `conversations`.state = ' . Conversation::STATE_DRAFT . ')';
						}
						
						// Include public conversations
						$rawSqlBuilder .= " OR JSON_EXTRACT(meta, '$.\"internal_conversations.is_public\"') = 'true'";


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
								$query->whereRaw( "(`conversations`.type != $customType OR `conversations`.id IN (" . implode( ',', $allowedConversations ) . "))" );
						} else {
								$query->where( 'conversations.type', '!=', $customType );
						}


						return $query;
				}, 10, 3 );
				\Eventy::addFilter( 'globalmailbox.conversations_query', function ( $query, $folder, $user_id ) {
						$customType = Conversation::TYPE_CUSTOM;

						$teamIds = [];
						if ( class_exists( Teams::class ) ) {
								$teamsForUser = User::whereRaw( "last_name = '" . Teams::TEAM_USER_LAST_NAME . "' and email like '" . Teams::TEAM_USER_EMAIL_PREFIX . "%' and JSON_CONTAINS(emails,'\"$user_id\"','$')" )->get();
								$teamIds      = $teamsForUser->pluck( 'id' )->toArray();
						}
						$rawSqlBuilder = "JSON_CONTAINS(`conversations`.meta, '\"$user_id\"', '$.\"internal_conversations.users\"')";

						foreach ( $teamIds as $teamId ) {
								$rawSqlBuilder .= " OR JSON_CONTAINS(`conversations`.meta, '\"$teamId\"', '$.\"internal_conversations.users\"')";
						}

						if ( $folder->type == Folder::TYPE_DRAFTS ) {
								$rawSqlBuilder .= ' OR (`conversations`.created_by_user_id = ' . $user_id . ' AND `conversations`.state = ' . Conversation::STATE_DRAFT . ')';
						}
						
						// Include public conversations
						$rawSqlBuilder .= " OR JSON_EXTRACT(`conversations`.meta, '$.\"internal_conversations.is_public\"') = 'true'";


						if ( is_int( $user_id ) ) {
								$user = User::find( $user_id );
						}
						if ( empty( $mailbox_ids ) ) {
								$mailbox_ids = $user->mailboxesIdsCanView();
						}
						if ( $folder->type == - 100 ) {
								// Inbox.
								$query_conversations = Conversation::whereIn( 'mailbox_id', $mailbox_ids )
								                                   ->where( 'state', Conversation::STATE_PUBLISHED );

								//} elseif ($folder->type == self::FOLDER_SENT) {


						} else if ( $folder->type == Folder::TYPE_MINE ) {
								// Get conversations from personal folder
								$query_conversations = Conversation::where( 'user_id', $user->id )
								                                   ->whereIn( 'status', [ Conversation::STATUS_ACTIVE, Conversation::STATUS_PENDING ] )
								                                   ->where( 'state', Conversation::STATE_PUBLISHED );

						} else if ( $folder->type == Folder::TYPE_ASSIGNED ) {

								// Assigned - do not show my conversations
								$query_conversations =
										// This condition also removes from result records with user_id = null
										Conversation::whereIn( 'mailbox_id', $mailbox_ids )
										            ->where( 'user_id', '<>', $user->id )
										            ->whereIn( 'status', [ Conversation::STATUS_ACTIVE, Conversation::STATUS_PENDING ] )
										            ->where( 'state', Conversation::STATE_PUBLISHED );

						} else if ( $folder->type == Folder::TYPE_STARRED ) {
								$starred_conversation_ids = ConversationFolder::join( 'folders', 'conversation_folder.folder_id', '=', 'folders.id' )
								                                              ->whereIn( 'folders.mailbox_id', $mailbox_ids )
								                                              ->where( 'folders.user_id', $user->id )
								                                              ->where( 'folders.type', Folder::TYPE_STARRED )
								                                              ->pluck( 'conversation_folder.conversation_id' );
								$query_conversations      = Conversation::whereIn( 'id', $starred_conversation_ids );

						} else if ( $folder->isIndirect() ) {

								// Conversations are connected to folder via conversation_folder table.
								$query_conversations = Conversation::select( 'conversations.*' )
								                                   ->join( 'conversation_folder', 'conversations.id', '=', 'conversation_folder.conversation_id' )
								                                   ->join( 'folders', 'conversation_folder.folder_id', '=', 'folders.id' )
								                                   ->whereIn( 'folders.mailbox_id', $mailbox_ids )
								                                   ->where( 'folders.type', $folder->type );

								if ( $folder->type != Folder::TYPE_DRAFTS ) {
										$query_conversations->where( 'state', Conversation::STATE_PUBLISHED );
								}

						} else if ( $folder->type == Folder::TYPE_DELETED ) {
								$query_conversations = Conversation::whereIn( 'mailbox_id', $mailbox_ids )
								                                   ->where( 'state', Conversation::STATE_DELETED );
						} else if ( \Module::isActive( 'teams' ) && $folder->type == \Modules\Teams\Providers\TeamsServiceProvider::FOLDER_TYPE ) {
								$team_id             = - 1000 - $folder->id;
								$query_conversations =
										Conversation::whereIn( 'mailbox_id', $mailbox_ids )
										            ->where( 'user_id', $team_id )
										            ->whereIn( 'status', [ Conversation::STATUS_ACTIVE, Conversation::STATUS_PENDING ] )
										            ->where( 'state', Conversation::STATE_PUBLISHED );
						} else {
								// Get all folders of this type.
								$folder_ids          = Folder::whereIn( 'mailbox_id', $mailbox_ids )
								                             ->where( 'type', $folder->type )
								                             ->pluck( 'id' );
								$query_conversations = Conversation::whereIn( 'folder_id', $folder_ids )
								                                   ->where( 'state', Conversation::STATE_PUBLISHED );
						}


						$allowedConversations = $query_conversations->where( 'conversations.type', $customType )->whereRaw( $rawSqlBuilder )->pluck( 'id' )->toArray();

						if ( count( $allowedConversations ) > 0 ) {
								$query->whereRaw( "(`conversations`.type != $customType OR `conversations`.id IN (" . implode( ',', $allowedConversations ) . "))" );
						} else {
								$query->where( 'conversations.type', '!=', $customType );
						}


						return $query;
				}, 10, 3 );
				\Eventy::addFilter( 'search.conversations.apply_filters', function ( $query, $filters, $q ) {
						$user_id    = auth()->user()->id;
						$customType = Conversation::TYPE_CUSTOM;

						$teamIds = [];
						if ( class_exists( Teams::class ) ) {
								$teamsForUser = User::whereRaw( "last_name = '" . Teams::TEAM_USER_LAST_NAME . "' and email like '" . Teams::TEAM_USER_EMAIL_PREFIX . "%' and JSON_CONTAINS(emails,'\"$user_id\"','$')" )->get();
								$teamIds      = $teamsForUser->pluck( 'id' )->toArray();
						}
						$rawSqlBuilder = "JSON_CONTAINS(`conversations`.meta, '\"$user_id\"', '$.\"internal_conversations.users\"')";

						foreach ( $teamIds as $teamId ) {
								$rawSqlBuilder .= " OR JSON_CONTAINS(`conversations`.meta, '\"$teamId\"', '$.\"internal_conversations.users\"')";
						}
						
						// Include public conversations
						$rawSqlBuilder .= " OR JSON_EXTRACT(`conversations`.meta, '$.\"internal_conversations.is_public\"') = 'true'";

						$query_conversations  = Conversation::where( 'state', Conversation::STATE_PUBLISHED );
						$allowedConversations = $query_conversations->where( 'type', $customType )->whereRaw( $rawSqlBuilder )->pluck( 'id' )->toArray();

						if ( count( $allowedConversations ) > 0 ) {
								$query->whereRaw( "(`conversations`.type != $customType OR `conversations`.id IN (" . implode( ',', $allowedConversations ) . "))" );
						} else {
								$query->where( 'conversations.type', '!=', $customType );
						}

						return $query;
				}, 10, 3 );

				\Eventy::addAction( 'conversation.view.start', function ( Conversation $conversation, $request ) {
						if ( ! $conversation->isCustom() ) {
								return;
						}
						$user_id = auth()->user()->id;
						
						// Check if conversation is public
						$isPublic = $conversation->getMeta( 'internal_conversations.is_public', false );
						if ( $isPublic ) {
								// Public conversations are accessible to all users with mailbox access
								$mailbox = $conversation->mailbox;
								if ( $mailbox && $mailbox->userHasAccess( $user_id ) ) {
										return; // Allow access
								}
						}
						
						//redirect away if not allowed
						$teamIds = [];
						if ( class_exists( Teams::class ) ) {
								$teamsForUser = User::whereRaw( "last_name = '" . Teams::TEAM_USER_LAST_NAME . "' and email like '" . Teams::TEAM_USER_EMAIL_PREFIX . "%' and JSON_CONTAINS(emails,'\"$user_id\"','$')" )->get();
								$teamIds      = $teamsForUser->pluck( 'id' )->toArray();
						}


						$connectedUsers = $conversation->getMeta( 'internal_conversations.users', [] );

						if ( $conversation->state === Conversation::STATE_DRAFT ) {
								$connectedUsers[] = $conversation->created_by_user_id;
						}

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
				// Add internal conversation events to subscription system
				\Eventy::addFilter( 'subscription.events', function( $events ) {
						$events[ self::EVENT_IC_NEW_MESSAGE ] = __( 'New internal conversation' );
						$events[ self::EVENT_IC_NEW_REPLY ] = __( 'Reply to internal conversation' );
						$events[ self::EVENT_IC_THUMBS_UP ] = __( 'Thumbs up on internal conversation' );
						return $events;
				}, 20 );

				// Note added.
				\Eventy::addAction( 'conversation.note_added', function ( Conversation $conversation, $thread ) {
						if ( $conversation->isCustom() ) {
								Subscription::registerEvent( self::EVENT_IC_NEW_REPLY, $conversation, $thread->created_by_user_id );
								
								// If conversation is public, add the user to connected users
								$isPublic = $conversation->getMeta( 'internal_conversations.is_public', false );
								if ( $isPublic && $thread->created_by_user_id ) {
										$connectedUsers = $conversation->getMeta( 'internal_conversations.users', [] );
										$userId = (string) $thread->created_by_user_id;
										
										if ( ! in_array( $userId, $connectedUsers ) ) {
												$connectedUsers[] = $userId;
												$conversation->setMeta( 'internal_conversations.users', $connectedUsers );
												// Ensure public state is preserved
												$conversation->setMeta( 'internal_conversations.is_public', true );
												$conversation->save();
										}
								}
						}
				}, 20, 2 );
				
				// Thumbs up given.
				\Eventy::addAction( 'internal_conversation.thumbs_up', function ( Conversation $conversation, $thread, $userId ) {
						if ( $conversation->isCustom() ) {
								Subscription::registerEvent( self::EVENT_IC_THUMBS_UP, $conversation, $userId );
						}
				}, 20, 3 );

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
						if ( $subscription->event !== self::EVENT_IC_NEW_MESSAGE && $subscription->event !== self::EVENT_IC_NEW_REPLY && $subscription->event !== self::EVENT_IC_THUMBS_UP ) {
								return $filter_out;
						}
						
						// For thumbs up events, only filter out if user is not the thread author
						if ( $subscription->event === self::EVENT_IC_THUMBS_UP ) {
								if ( $thread && $thread->created_by_user_id == $subscription->user_id ) {
										return false; // Don't filter out - this is the thread author
								}
								return $filter_out;
						}
						
						// Check if conversation is public
						$isPublic = $thread->conversation->getMeta( 'internal_conversations.is_public', false );
						if ( $isPublic ) {
								// For public conversations, check if user has mailbox access
								$mailbox = $thread->conversation->mailbox;
								if ( $mailbox && $mailbox->userHasAccess( $subscription->user_id ) ) {
										return false; // Allow notifications for public conversations
								}
						}
						
						// For other events, check if user is connected to the conversation
						$connectedUsers = $thread->conversation->getMeta( 'internal_conversations.users', [] );

						if ( ! in_array( $subscription->user_id, $connectedUsers ) ) {
								return true;
						} else {
								return false;
						}
				}, 20, 3 );

				\Eventy::addFilter( 'subscription.is_related_to_user', function ( $is_related, $subscription, $thread ) {
						if ( $subscription->event !== self::EVENT_IC_NEW_MESSAGE && $subscription->event !== self::EVENT_IC_NEW_REPLY && $subscription->event !== self::EVENT_IC_THUMBS_UP ) {
								return $is_related;
						}
						
						// For thumbs up events, it's related if user is the thread author
						if ( $subscription->event === self::EVENT_IC_THUMBS_UP ) {
								if ( $thread && $thread->created_by_user_id == $subscription->user_id ) {
										return true;
								}
								return $is_related;
						}
						
						// Check if conversation is public
						$isPublic = $thread->conversation->getMeta( 'internal_conversations.is_public', false );
						if ( $isPublic ) {
								// For public conversations, check if user has mailbox access
								$mailbox = $thread->conversation->mailbox;
								if ( $mailbox && $mailbox->userHasAccess( $subscription->user_id ) ) {
										return true; // User is related to public conversations they have access to
								}
						}
						
						// For other events, check if user is connected to the conversation
						$connectedUsers = $thread->conversation->getMeta( 'internal_conversations.users', [] );

						if ( in_array( $subscription->user_id, $connectedUsers ) ) {
								return true;
						}

						return $is_related;
				}, 20, 3 );

				// Always show @mentions notification in the menu.
				\Eventy::addFilter( 'subscription.users_to_notify', function ( $users_to_notify, $event_type, $events, $thread ) {
						if ( in_array( self::EVENT_IC_NEW_MESSAGE, $events ) || in_array( self::EVENT_IC_NEW_REPLY, $events ) ) {
								// Check if conversation is public
								$isPublic = $thread->conversation->getMeta( 'internal_conversations.is_public', false );
								
								if ( $isPublic ) {
										// For public conversations, notify all users with mailbox access
										$mailbox = $thread->conversation->mailbox;
										if ( $mailbox ) {
												$users = $mailbox->usersAssignable( true );
												foreach ( $users as $user ) {
														$users_to_notify[ Subscription::MEDIUM_MENU ][] = $user;
												}
												$users_to_notify[ Subscription::MEDIUM_MENU ] = array_unique( $users_to_notify[ Subscription::MEDIUM_MENU ] );
										}
								} else {
										// For private conversations, only notify connected users
										$connectedUsers = $thread->conversation->getMeta( 'internal_conversations.users', [] );
										if ( count( $connectedUsers ) > 0 ) {
												$users = User::whereIn( 'id', $connectedUsers )->get();
												foreach ( $users as $user ) {
														$users_to_notify[ Subscription::MEDIUM_MENU ][] = $user;
														$users_to_notify[ Subscription::MEDIUM_MENU ]   = array_unique( $users_to_notify[ Subscription::MEDIUM_MENU ] );
												}
										}
								}
						}
						
						// Handle thumbs up notifications
						if ( in_array( self::EVENT_IC_THUMBS_UP, $events ) ) {
								// Notify the thread author
								if ( $thread && $thread->created_by_user_id ) {
										$threadAuthor = User::find( $thread->created_by_user_id );
										if ( $threadAuthor ) {
												$users_to_notify[ Subscription::MEDIUM_MENU ][] = $threadAuthor;
												// Also send email if user has email notifications enabled for thumbs up
												if ( $threadAuthor->isSubscribed( self::EVENT_IC_THUMBS_UP, Subscription::MEDIUM_EMAIL ) ) {
														$users_to_notify[ Subscription::MEDIUM_EMAIL ][] = $threadAuthor;
												}
										}
								}
								
								// Remove duplicates
								$users_to_notify[ Subscription::MEDIUM_MENU ] = array_unique( $users_to_notify[ Subscription::MEDIUM_MENU ] );
								if ( isset( $users_to_notify[ Subscription::MEDIUM_EMAIL ] ) ) {
										$users_to_notify[ Subscription::MEDIUM_EMAIL ] = array_unique( $users_to_notify[ Subscription::MEDIUM_EMAIL ] );
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

				\Eventy::addFilter( 'subscription.is_user_assignee', function ( $is_assignee, $subscription, $conversation ) {
						if ( ! $conversation->isCustom() ) {
								return $is_assignee;
						}

						$connectedUsers = $conversation->getMeta( 'internal_conversations.users', [] );
						if ( in_array( $subscription->user_id, $connectedUsers ) ) {
								return true;
						}

						return $is_assignee;
				}, 20, 3 );
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
