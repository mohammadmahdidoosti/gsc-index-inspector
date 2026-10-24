<?php
/**
 * Google auth (Service Account / JWT) + URL Inspection API client.
 * بدون نیاز به Composer یا google/apiclient.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GSCII_Google_Client {

	const SCOPE       = 'https://www.googleapis.com/auth/webmasters.readonly';
	const TOKEN_URL   = 'https://oauth2.googleapis.com/token';
	const INSPECT_URL = 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect';

	/**
	 * کلید سرویس اکانت را می‌خواند.
	 * اولویت: ثابت GSCII_SERVICE_ACCOUNT_FILE در wp-config.php ، بعد تنظیمات افزونه.
	 *
	 * @return array|WP_Error
	 */
	public static function get_credentials() {
		$json = '';

		if ( defined( 'GSCII_SERVICE_ACCOUNT_FILE' ) && GSCII_SERVICE_ACCOUNT_FILE ) {
			if ( ! is_readable( GSCII_SERVICE_ACCOUNT_FILE ) ) {
				return new WP_Error( 'gscii_creds_file', 'فایل کلید تعریف‌شده در GSCII_SERVICE_ACCOUNT_FILE قابل خواندن نیست.' );
			}
			$json = file_get_contents( GSCII_SERVICE_ACCOUNT_FILE ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		} else {
			$json = get_option( 'gscii_service_account', '' );
		}

		if ( empty( $json ) ) {
			return new WP_Error( 'gscii_no_creds', 'کلید Service Account تنظیم نشده است.' );
		}

		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || empty( $data['client_email'] ) || empty( $data['private_key'] ) ) {
			return new WP_Error( 'gscii_bad_creds', 'کلید Service Account معتبر نیست (client_email یا private_key ندارد).' );
		}

		return $data;
	}

	public static function credentials_from_constant() {
		return defined( 'GSCII_SERVICE_ACCOUNT_FILE' ) && GSCII_SERVICE_ACCOUNT_FILE;
	}

	private static function b64url( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * دریافت Access Token با JWT امضاشده (RS256). توکن در transient کش می‌شود.
	 *
	 * @param bool $force
	 * @return string|WP_Error
	 */
	public static function get_access_token( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( 'gscii_access_token' );
			if ( $cached ) {
				return $cached;
			}
		}

		$creds = self::get_credentials();
		if ( is_wp_error( $creds ) ) {
			return $creds;
		}

		if ( ! function_exists( 'openssl_sign' ) ) {
			return new WP_Error( 'gscii_no_openssl', 'افزونه‌ی OpenSSL در PHP سرور فعال نیست.' );
		}

		$now    = time();
		$header = self::b64url( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
		$claims = self::b64url( wp_json_encode( array(
			'iss'   => $creds['client_email'],
			'scope' => self::SCOPE,
			'aud'   => self::TOKEN_URL,
			'iat'   => $now,
			'exp'   => $now + 3600,
		) ) );

		$signing_input = $header . '.' . $claims;
		$private_key   = openssl_pkey_get_private( $creds['private_key'] );
		if ( ! $private_key ) {
			return new WP_Error( 'gscii_bad_key', 'کلید خصوصی (private_key) قابل بارگذاری نیست.' );
		}

		$signature = '';
		if ( ! openssl_sign( $signing_input, $signature, $private_key, OPENSSL_ALGO_SHA256 ) ) {
			return new WP_Error( 'gscii_sign_failed', 'امضای JWT ناموفق بود.' );
		}

		$jwt = $signing_input . '.' . self::b64url( $signature );

		$response = wp_remote_post( self::TOKEN_URL, array(
			'timeout' => 20,
			'body'    => array(
				'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
				'assertion'  => $jwt,
			),
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'gscii_token_http', 'اتصال به سرور توکن گوگل ناموفق بود: ' . $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $body['access_token'] ) ) {
			$msg = isset( $body['error_description'] ) ? $body['error_description'] : ( isset( $body['error'] ) ? $body['error'] : 'HTTP ' . $code );
			return new WP_Error( 'gscii_token_error', 'خطا در دریافت توکن: ' . $msg );
		}

		$ttl = max( 60, (int) ( isset( $body['expires_in'] ) ? $body['expires_in'] : 3600 ) - 120 );
		set_transient( 'gscii_access_token', $body['access_token'], $ttl );

		return $body['access_token'];
	}

	/**
	 * درخواست عمومی JSON به APIهای گوگل با همان توکن (مثل Search Analytics).
	 *
	 * @return array|WP_Error بدنه‌ی پاسخ
	 */
	public static function request( $method, $url, $body = null, $retry_on_401 = true ) {
		$token = self::get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$args = array(
			'method'  => $method,
			'timeout' => 45,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'gscii_api_http', 'اتصال به API گوگل ناموفق بود: ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 401 === $code && $retry_on_401 ) {
			delete_transient( 'gscii_access_token' );
			return self::request( $method, $url, $body, false );
		}
		if ( $code < 200 || $code >= 300 ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'HTTP ' . $code;
			return new WP_Error( 'gscii_api_error', $msg, array( 'status' => $code ) );
		}
		return is_array( $data ) ? $data : array();
	}

	public static function get_property() {
		return trim( (string) get_option( 'gscii_property', '' ) );
	}

	/**
	 * فراخوانی URL Inspection API.
	 *
	 * @param string $url
	 * @param bool   $retry_on_401
	 * @return array|WP_Error نتیجه‌ی نرمال‌شده
	 */
	public static function inspect( $url, $retry_on_401 = true ) {
		$url = esc_url_raw( trim( $url ) );
		if ( empty( $url ) ) {
			return new WP_Error( 'gscii_bad_url', 'آدرس وارد شده معتبر نیست.' );
		}

		$property = self::get_property();
		if ( empty( $property ) ) {
			return new WP_Error( 'gscii_no_property', 'Property سرچ کنسول در تنظیمات افزونه وارد نشده است.' );
		}

		$token = self::get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = wp_remote_post( self::INSPECT_URL, array(
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( array(
				'inspectionUrl' => $url,
				'siteUrl'       => $property,
				'languageCode'  => get_option( 'gscii_language', 'fa' ),
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'gscii_api_http', 'اتصال به Search Console API ناموفق بود: ' . $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 401 === $code && $retry_on_401 ) {
			delete_transient( 'gscii_access_token' );
			return self::inspect( $url, false );
		}

		if ( 200 !== $code ) {
			$msg = isset( $body['error']['message'] ) ? $body['error']['message'] : 'HTTP ' . $code;
			if ( 403 === $code ) {
				$msg .= ' — مطمئن شوید ایمیل Service Account در سرچ کنسول به این Property اضافه شده و Property دقیقاً درست وارد شده است.';
			} elseif ( 429 === $code ) {
				$msg .= ' — سهمیه‌ی API تمام شده؛ بعداً تلاش کنید.';
			}
			return new WP_Error( 'gscii_api_error', $msg, array( 'status' => $code ) );
		}

		return self::normalize( is_array( $body ) ? $body : array(), $url );
	}

	private static function normalize( array $body, $url ) {
		$result = isset( $body['inspectionResult'] ) ? $body['inspectionResult'] : array();
		$index  = isset( $result['indexStatusResult'] ) ? $result['indexStatusResult'] : array();

		$get = function ( $key, $default = '' ) use ( $index ) {
			return isset( $index[ $key ] ) ? $index[ $key ] : $default;
		};

		$rich        = '';
		$rich_issues = array();
		if ( isset( $result['richResultsResult']['verdict'] ) ) {
			$rich = $result['richResultsResult']['verdict'];
		}
		if ( ! empty( $result['richResultsResult']['detectedItems'] ) && is_array( $result['richResultsResult']['detectedItems'] ) ) {
			foreach ( $result['richResultsResult']['detectedItems'] as $det ) {
				$type = isset( $det['richResultType'] ) ? (string) $det['richResultType'] : '';
				foreach ( isset( $det['items'] ) ? (array) $det['items'] : array() as $item ) {
					foreach ( isset( $item['issues'] ) ? (array) $item['issues'] : array() as $iss ) {
						$key                 = md5( $type . '|' . ( isset( $iss['issueMessage'] ) ? $iss['issueMessage'] : '' ) );
						$rich_issues[ $key ] = array(
							'type'     => $type,
							'msg'      => isset( $iss['issueMessage'] ) ? (string) $iss['issueMessage'] : '',
							'severity' => isset( $iss['severity'] ) ? (string) $iss['severity'] : '',
						);
					}
				}
			}
			$rich_issues = array_slice( array_values( $rich_issues ), 0, 20 );
		}

		return array(
			'url'             => $url,
			'verdict'         => $get( 'verdict', 'VERDICT_UNSPECIFIED' ),
			'coverageState'   => $get( 'coverageState' ),
			'indexingState'   => $get( 'indexingState' ),
			'robotsTxtState'  => $get( 'robotsTxtState' ),
			'pageFetchState'  => $get( 'pageFetchState' ),
			'lastCrawlTime'   => $get( 'lastCrawlTime' ),
			'crawledAs'       => $get( 'crawledAs' ),
			'googleCanonical' => $get( 'googleCanonical' ),
			'userCanonical'   => $get( 'userCanonical' ),
			'sitemap'         => (array) $get( 'sitemap', array() ),
			'referringUrls'   => (array) $get( 'referringUrls', array() ),
			'richResults'     => $rich,
			'richIssues'      => $rich_issues,
			'link'            => isset( $result['inspectionResultLink'] ) ? $result['inspectionResultLink'] : '',
			'checked_at'      => time(),
		);
	}
}
