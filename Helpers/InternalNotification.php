<?php

namespace Modules\InternalConversations\Helpers;

use App\Conversation;
use App\Notifications\WebsiteNotification;
use App\Thread;
use App\User;

class InternalNotification {
		protected static array $types = [];
		protected static bool $filtersRegistered = false;

		/**
		 * Register a notification type with its message template.
		 *
		 * @param string $type Unique identifier (e.g., 'thumbs_up')
		 * @param string $template Action text (e.g., 'gave you a 👍 on a note')
		 */
		public static function registerType( string $type, string $template ): void {
				self::$types[ $type ] = $template;
				self::ensureFiltersRegistered();
		}

		/**
		 * Get all registered types (for testing).
		 */
		public static function getTypes(): array {
				return self::$types;
		}

		/**
		 * Check if a type is registered.
		 */
		public static function hasType( string $type ): bool {
				return isset( self::$types[ $type ] );
		}

		/**
		 * Register notification display filters (called once automatically).
		 */
		protected static function ensureFiltersRegistered(): void {
				if ( self::$filtersRegistered ) {
						return;
				}
				self::$filtersRegistered = true;

				// Customize the notification header text
				\Eventy::addFilter( 'web_notification.header', function ( $text, $notification_data ) {
						$type = $notification_data['data']['type'] ?? null;

						if ( ! $type || ! isset( self::$types[ $type ] ) ) {
								return $text;
						}

						$actor        = User::find( $notification_data['data']['user_id'] ?? null );
						$conversation = Conversation::find( $notification_data['data']['conversation_id'] ?? null );

						if ( ! $actor || ! $conversation ) {
								return $text;
						}

						$template = self::$types[ $type ];

						return '<strong>' . e( $actor->getFirstName() ) . '</strong> '
						       . $template
						       . ' #' . $conversation->number;
				}, 20, 2 );

				// Show the actor's avatar
				\Eventy::addFilter( 'web_notification.person', function ( $user, $notification_data ) {
						$type = $notification_data['data']['type'] ?? null;

						if ( ! $type || ! isset( self::$types[ $type ] ) ) {
								return $user;
						}

						return User::find( $notification_data['data']['user_id'] ?? null ) ?? $user;
				}, 20, 2 );
		}

		/**
		 * Send notification to specific users.
		 *
		 * @param string $type Registered notification type
		 * @param User $actor User who triggered the action
		 * @param Conversation $conversation The conversation
		 * @param array|User|iterable $recipients User(s) to notify
		 */
		public static function sendTo(
				string       $type,
				User         $actor,
				Conversation $conversation,
				             $recipients,
				?Thread      $thread = null
		): void {
				if ( ! isset( self::$types[ $type ] ) ) {
						\Log::warning( "InternalNotification: Unknown type '{$type}'" );

						return;
				}

				// Normalize recipients to array
				if ( $recipients instanceof User ) {
						$recipients = [ $recipients ];
				} else if ( $recipients instanceof \Traversable ) {
						$recipients = iterator_to_array( $recipients );
				}

				// Filter out the actor (never notify yourself)
				$recipients = array_filter( $recipients, fn( $user ) => $user->id !== $actor->id );

				if ( empty( $recipients ) ) {
						return;
				}

				$thread = $thread ?? $conversation->threads()->first();

				$notification = new WebsiteNotification(
						$conversation,
						$thread,
						[
								'type'            => $type,
								'user_id'         => $actor->id,
								'conversation_id' => $conversation->id,
						]
				);

				\Notification::send( $recipients, $notification );
		}

		/**
		 * Send notification to all connected users of the conversation.
		 *
		 * @param string $type Registered notification type
		 * @param User $actor User who triggered the action
		 * @param Conversation $conversation The conversation
		 */
		public static function sendToConnected(
				string       $type,
				User         $actor,
				Conversation $conversation,
				?Thread      $thread = null
		): void {
				$userIds = $conversation->getMeta( 'internal_conversations.users', [] );

				if ( empty( $userIds ) ) {
						return;
				}

				$recipients = User::whereIn( 'id', $userIds )->get();

				self::sendTo( $type, $actor, $conversation, $recipients, $thread );
		}
}
