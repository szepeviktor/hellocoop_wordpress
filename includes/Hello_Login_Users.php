<?php
/**
 * Hello Users service.
 *
 * @package   Hello_Login
 * @category  Login
 * @author    Marius Scurtescu <marius.scurtescu@hello.coop>
 * @copyright 2023  Hello Identity Co-op
 * @license   http://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */

/**
 * Hello specific user service.
 *
 * @package Hello_Login
 */
class Hello_Login_Users {
	/**
	 * The user meta key used to store the Hellō subject identifier.
	 *
	 * @var string
	 */
	const SUBJECT_META = 'hello-login-subject-identity';

	/**
	 * Plugin logger.
	 *
	 * @var Hello_Login_Option_Logger
	 */
	private Hello_Login_Option_Logger $logger;

	/**
	 * Plugin settings object.
	 *
	 * @var Hello_Login_Option_Settings
	 */
	private Hello_Login_Option_Settings $settings;

	/**
	 * The class constructor.
	 *
	 * @param Hello_Login_Option_Logger   $logger
	 * @param Hello_Login_Option_Settings $settings
	 */
	public function __construct( Hello_Login_Option_Logger $logger, Hello_Login_Option_Settings $settings ) {
		$this->logger = $logger;
		$this->settings = $settings;
	}

	/**
	 * Get the Hellō subject identifier for the given user.
	 *
	 * @param int|WP_User|null $user The user id of the user, if NULL then use current user.
	 *
	 * @return string
	 */
	public static function get_hello_sub( $user = null ): string {
		if ( is_null( $user ) ) {
			$user = get_current_user_id();
		} else if ( $user instanceof WP_User ) {
			$user = $user->ID;
		}

		return get_user_meta( $user, self::SUBJECT_META, true );
	}

	/**
	 * Get the Hellō subject identifier for the given user.
	 *
	 * @param int|WP_User $user The user id of the user, if NULL then use current user.
	 *
	 * @return int|false
	 */
	public static function add_hello_sub( $user, string $sub ) {
		if ( $user instanceof WP_User ) {
			$user = $user->ID;
		}

		return add_user_meta( $user, self::SUBJECT_META, $sub, true );
	}

	/**
	 * Get the Hellō subject identifier for the given user.
	 *
	 * @param int|WP_User|null $user The user id of the user, if NULL then use current user.
	 *
	 * @return int|bool
	 */
	public static function update_hello_sub( $user, string $sub ) {
		if ( $user instanceof WP_User ) {
			$user = $user->ID;
		}

		return update_user_meta( $user, self::SUBJECT_META, $sub, true );
	}

	/**
	 * Get the Hellō subject identifier for the given user.
	 *
	 * @param int|WP_User|null $user The user id of the user, if NULL then use current user.
	 *
	 * @return bool
	 */
	public static function delete_hello_sub( $user = null ): bool {
		if ( is_null( $user ) ) {
			$user = get_current_user_id();
		} else if ( $user instanceof WP_User ) {
			$user = $user->ID;
		}

		return delete_user_meta( $user, self::SUBJECT_META );
	}

	/**
	 * Get the user that has metadata matching a given Hellō identifier.
	 *
	 * @param string $subject_identity The Hellō user identifier.
	 *
	 * @return false|WP_User
	 */
	public function get_user_by_identity( string $subject_identity ) {
		$user_query = new WP_User_Query(
			array(
				'meta_query' => array(
					array(
						'key'   => self::SUBJECT_META,
						'value' => $subject_identity,
					),
				),
				// Override the default blog_id (get_current_blog_id) to find users on different sites of a multisite install.
				'blog_id' => 0,
			)
		);

		// If we found existing users, grab the first one returned.
		$total = $user_query->get_total();
		if ( $total > 0 ) {
			if ( $total > 1 ) {
				$this->logger->log( "ERROR: $total users found with subject identifier $subject_identity", 'get_user_by_identity' );
			}

			$users = $user_query->get_results();
			return $users[0];
		}

		return false;
	}

	/**
	 * Create a new user from details in a user_claim.
	 *
	 * @param string $subject_identity The Hellō subject identifier.
	 * @param array  $user_data         User creation attributes as required by wp_insert_user.
	 * @param bool   $force_linking      If true then force account linking regardless of settings.
	 *
	 * @return WP_Error|WP_User
	 */
	public function create_new_user( string $subject_identity, array $user_data, bool $force_linking = false ) {
		$user_data['user_pass'] = wp_generate_password( 32, true, true );

		// Before trying to create the user, first check if a matching user exists.
		if ( $force_linking || $this->settings->link_existing_users ) {
			$uid = email_exists( $user_data['user_email'] );

			if ( ! empty( $uid ) ) {
				$user = $this->link_existing_user( $uid, $subject_identity );

				if ( is_wp_error( $user ) ) {
					return $user;
				}

				do_action( 'hello-login-update-user-using-current-claim', $user, $user_data );
				return $user;
			}
		}

		/**
		 * Allow other plugins / themes to determine authorization of new accounts
		 * based on the returned user claim.
		 */
		$create_user = apply_filters( 'hello-login-user-creation-test', $this->settings->create_if_does_not_exist, $user_data );

		if ( ! $create_user ) {
			return new WP_Error( 'cannot-authorize', __( 'Can not authorize.', 'hello-login' ), $create_user );
		}

		// Copy the username for incrementing.
		$username = $user_data['user_login'];
		$_username = $username;
		// Ensure prevention of linking usernames & collisions by incrementing the username if it exists.
		// @example Original user gets "name", second user gets "name2", etc.
		$count = 1;
		while ( username_exists( $username ) ) {
			$count ++;
			$username = $_username . $count;
		}

		$user_data['user_login'] = $username;

		$user_data = apply_filters( 'hello-login-alter-user-data', $user_data );

		// Create the new user.
		$uid = wp_insert_user( $user_data );

		// Make sure we didn't fail in creating the user.
		if ( is_wp_error( $uid ) ) {
			return new WP_Error( 'failed-user-creation', __( 'Failed user creation.', 'hello-login' ), $uid );
		}

		// Retrieve our new user.
		$user = get_user_by( 'id', $uid );

		// Save some metadata about this new user for the future.
		self::add_hello_sub( $user, $subject_identity );

		// Log the results.
		$this->logger->log( "New user created: {$user->user_login} ($uid)", 'success' );

		// Allow plugins / themes to take action on new user creation.
		do_action( 'hello-login-user-create', $user );

		return $user;
	}

	/**
	 * Update an existing user with OpenID Connect metadata
	 *
	 * @param int    $uid              The WordPress User ID.
	 * @param string $subject_identity The Hellō subject identifier.
	 *
	 * @return WP_Error|WP_User
	 */
	public function link_existing_user( int $uid, string $subject_identity ) {
		$uid_hello_sub = self::get_hello_sub( $uid );
		if ( ! empty( $uid_hello_sub ) ) {
			// Existing user already linked.
			if ( $uid_hello_sub !== $subject_identity ) {
				$link_error = new WP_Error( 'user_link_error', __( 'User already linked to a different Hellō account.', 'hello-login' ) );
				$link_error->add_data( $subject_identity );
				$link_error->add_data( $uid_hello_sub );
				$link_error->add_data( $uid );

				return $link_error;
			} else {
				// Existing user already linked to the same account, this is a NOOP.
				return get_user_by( 'id', $uid );
			}
		}

		self::update_hello_sub( $uid, $subject_identity );

		// Allow plugins / themes to take action on user update.
		do_action( 'hello-login-user-update', $uid );

		// Return our updated user.
		return get_user_by( 'id', $uid );
	}

	/**
	 * Save extra user claims as user metadata.
	 *
	 * @param int   $uid The WordPress User ID.
	 * @param array $user_claim The user claim.
	 * @return void
	 */
	public function save_extra_claims( int $uid, array $user_claim ) {
		foreach ( $user_claim as $key => $value ) {
			if ( ! in_array( $key, array( 'iss', 'sub', 'aud', 'exp', 'iat', 'jti', 'auth_time', 'nonce', 'acr', 'amr', 'azp' ) ) ) {
				if ( update_user_meta( $uid, 'hello-login-claim-' . $key, $value ) ) {
					$this->logger->log( 'User claim saved as meta: hello-login-claim-' . $key . ' = ' . $value, 'user-claims' );
				}
			}
		}
	}

	/**
	 * Update user claims as user metadata.
	 *
	 * @param WP_User $user The WordPress User.
	 * @param array   $user_claim The user claim.
	 *
	 * @return void
	 */
	public function update_user_claims( WP_User $user, array $user_claim ) {
		$uid = $user->ID;

		if ( isset( $user_claim['given_name'] ) && empty( get_user_meta( $uid, 'first_name', true ) ) ) {
			if ( update_user_meta( $uid, 'first_name', $user_claim['given_name'] ) ) {
				$this->logger->log( 'User first name saved: ' . $user_claim['given_name'], 'user-claims' );
			} else {
				$this->logger->log( 'Failed saving user first name.', 'user-claims' );
			}
		}

		if ( isset( $user_claim['family_name'] ) && empty( get_user_meta( $uid, 'last_name', true ) ) ) {
			if ( update_user_meta( $uid, 'last_name', $user_claim['family_name'] ) ) {
				$this->logger->log( 'User last name saved: ' . $user_claim['family_name'], 'user-claims' );
			} else {
				$this->logger->log( 'Failed saving user last name.', 'user-claims' );
			}
		}

		if ( isset( $user_claim['email'] ) && $user_claim['email'] != $user->user_email ) {
			$res = wp_update_user(
				array(
					'ID' => $uid,
					'user_email' => $user_claim['email'],
				)
			);

			if ( is_wp_error( $res ) ) {
				$this->logger->log( $res );
				$this->logger->log( "Email update failed for user $uid to email {$user_claim['email']}", 'user-claims' );
			} else {
				$this->logger->log( "User email updated from $user->user_email to {$user_claim['email']}.", 'user-claims' );
				$user->user_email = $user_claim['email'];
			}
		}
	}
}
