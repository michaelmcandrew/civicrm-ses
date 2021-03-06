<?php
use CRM_Ses_ExtensionUtil as E;

/**
 * Simple Email Service webhook page class.
 * 
 * Listens and processes bounce events from Amazon SNS 
 */
class CRM_Ses_Page_Webhook extends CRM_Core_Page {

	/**
	 * Verp Separator.
	 *
	 * @access protected
	 * @var string $verp_separator
	 */
	protected $verp_separator;

	/**
	 * The SES Notification object.
	 *
	 * @access protected
	 * @var object $json
	 */
	protected $json;

	/**
	 * The SES Message object.
	 *
	 * @access protected
	 * @var object $message
	 */
	protected $message;

	/**
	 * The SES Bounce object.
	 *
	 * @access protected
	 * @var object $bounce
	 */
	protected $bounce;

	/**
	 * SES Permanent bounce types.
	 *
	 * Hard bounces
	 * @access protected
	 * @var array $ses_permanent_bounce_types
	 */
	protected $ses_permanent_bounce_types = [ 'Undetermined', 'General', 'NoEmail', 'Suppressed' ];

	/**
	 * SES Transient bounce types.
	 *
	 * Soft bounces
	 * @access protected
	 * @var array $ses_transient_bounce_types
	 */
	protected $ses_transient_bounce_types = [ 'General', 'MailboxFull', 'MessageTooLarge', 'ContentRejected', 'AttachmentRejected' ];

	/**
	 * CiviCRM Bounce types.
	 *
	 * @access protected 
	 * @var array $civi_bounce_types
	 */
	protected $civi_bounce_types = [
		1 => 'AOL',
		2 => 'Away',
		3 => 'Dns',
		4 => 'Host',
		5 => 'Inactive',
		6 => 'Invalid',
		7 => 'Loop',
		8 => 'Quota',
		9 => 'Relay',
		10 => 'Spam',
		11 => 'Syntax'
	];
	
	/**
	 * Guzzle exists.
	 * 
	 * @var bool $is_guzzle
	 */
	protected $is_guzzle = false;
	
	/**
	 * GuzzleHttp Clinet.
	 *
	 * @access public
	 * @var object $client Guzzle\Client
	 */
	protected $client;

	/**
	 * Constructor.
	 */
	public function __construct() {
		
		if ( class_exists( 'GuzzleHttp\Client' ) ) {
			$this->is_guzzle = true;
			$this->client = new GuzzleHttp\Client();
		}
		
		$this->verp_separator = Civi::settings()->get( 'verpSeparator' );
		// get json input
		$this->json = json_decode( file_get_contents( 'php://input' ) );
		// message object
		$this->message = json_decode( $this->json->Message );
		// bounce object
		$this->bounce = $this->message->bounce;

		parent::__construct();
	}

	/**
	 * Run.
	 */
  	public function run() {
  		
  		// confirm subscription
  		if( $this->json->Type == 'SubscriptionConfirmation' ) $this->confirm_subscription();

		if ( $this->json->Type != 'Notification' ) CRM_Utils_System::civiExit();

		if ( in_array( $this->message->notificationType, ['Bounce'] ) ) {
			// get verp items from X-CiviMail-Bounce header
		    list( $job_id, $event_queue_id, $hash ) = $this->get_verp_items( $this->get_header_value( 'X-CiviMail-Bounce' ) );

			$bounce_params = $this->set_bounce_type_params( [
				'job_id' => $job_id,
				'event_queue_id' => $event_queue_id,
				'hash' => $hash,
			] );

			if ( CRM_Utils_Array::value( 'bounce_type_id' , $bounce_params ) ) {
				$bounced = CRM_Mailing_Event_BAO_Bounce::create( $bounce_params );
			}
		}
		CRM_Utils_System::civiExit();
  	}

  	/**
  	 * Get header by name.
  	 *
  	 * @param  string $name The header name to retrieve
  	 * @return string $value The header value
  	 */
  	protected function get_header_value( $name ) {
		foreach ( $this->message->mail->headers as $key => $header ) {
			if( $header->name == $name )
				return $header->value;
		}
  	}

  	/**
  	 * Get verp items.
  	 *
  	 * @param string $header_value The X-CiviMail-Bounce header
  	 * @return array $verp_itmes The verp items [ $job_id, $queue_id, $hash ]
  	 */
  	protected function get_verp_items( $header_value ) {
  		$verp_itmes = substr( substr( $header_value, 0, strpos( $header_value, '@' ) ), 2 );
  		return explode( $this->verp_separator, $verp_items );
  	}

  	/**
  	 * Set bounce type params.
  	 *
  	 * @param array $bounce_params The params array
  	 * @return array $bounce_params Teh params array
  	 */
  	protected function set_bounce_type_params( $bounce_params ) {
		// hard bounces
		if ( $this->bounce->bounceType == 'Permanent' && in_array( $this->bounce->bounceSubType, $this->ses_permanent_bounce_types ) )
			switch ( $this->bounce->bounceSubType ) {
				case 'Undetermined':
					$bounce_params = $this->map_bounce_types( $bounce_params, 'Syntax' );
					break;
				case 'General':
				case 'NoEmail':
				case 'Suppressed':
					$bounce_params = $this->map_bounce_types( $bounce_params, 'Invalid' );
					break;
			}
		// soft bounces
		if ( $this->bounce->bounceType == 'Transient' && in_array( $this->bounce->bounceSubType, $this->ses_transient_bounce_types ) )
			switch ( $this->bounce->bounceSubType ) {
				case 'General':
					$bounce_params = $this->map_bounce_types( $bounce_params, 'Syntax' ); // hold_threshold is 3
					break;
				case 'MessageTooLarge':
				case 'MailboxFull':
					$bounce_params = $this->map_bounce_types( $bounce_params, 'Quota' );
					break;
				case 'ContentRejected':
				case 'AttachmentRejected':
					$bounce_params = $this->map_bounce_types( $bounce_params, 'Spam' );
					break;
			}

		return $bounce_params;
	}

	/**
	 * Map Amazon bounce types to Civi bounce types.
	 * 
	 * @param  array $bounce_params The params array
	 * @param  string $type_to_map_to Civi bounce type to map to
	 * @return array $bounce_params The params array
	 */
	protected function map_bounce_types( $bounce_params, $type_to_map_to ) {

		$bounce_params['bounce_type_id'] = array_search( $type_to_map_to, $this->civi_bounce_types );
		// it should be one recipient
		$recipient = count( $this->bounce->bouncedRecipients ) == 1 ? reset( $this->bounce->bouncedRecipients ) : false;
		if ( $recipient )
			$bounce_params['bounce_reason'] = $recipient->status . ' => ' . $recipient->diagnosticCode;

		return $bounce_params;
	}

	/**
	 * Confirm SNS subscription to topic.
	 */
	protected function confirm_subscription() {
		
		if ( $this->is_guzzle ) {
			// use guzzlehttp
			$this->client->request( 'POST', $this->json->SubscribeURL );
		} else { // use curl
			$curl_handle = curl_init();
			curl_setopt( $curl_handle, CURLOPT_URL, $this->json->SubscribeURL );
			curl_setopt( $curl_handle, CURLOPT_CONNECTTIMEOUT, 2 );
			curl_exec( $curl_handle );
			curl_close( $curl_handle );
		}
	}
}
