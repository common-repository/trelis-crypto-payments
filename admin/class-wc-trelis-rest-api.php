<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://www.Trelis.com
 * @since      1.0.0
 *
 * @package    Trelis_Crypto_Payments
 * @subpackage Trelis_Crypto_Payments/admin
 */

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    Trelis_Crypto_Payments
 * @subpackage Trelis_Crypto_Payments/admin
 * @author     Trelis <jalpesh.fullstack10@gmail.com>
 */
class WC_Trelis_Rest_Api extends WC_Payment_Gateway
{

	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	public $apiKey;
	public $apiSecret;
	public $isPrime;
	public $isGasless;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function __construct()
	{
		$this->apiKey = $this->get_option('api_key');
		$this->apiSecret = $this->get_option('api_secret');
		$this->isPrime = $this->get_option('prime') === "yes";
		$this->isGasless = $this->get_option('gasless') === "yes";
		add_action('rest_api_init', array($this, 'register_trelis_payment'));
	}

	public function register_trelis_payment()
	{
		register_rest_route(
			'trelis/v3',
			'/payment',
			array(
				'methods' => 'POST',
				'callback' => array($this, 'trelis_payment_confirmation_callback'),
				'permission_callback' => '__return_true'
			),
		);
	}

	/*
	* Payment callback Webhook, Used to process the payment callback from the payment gateway
	*/
	public function trelis_payment_confirmation_callback()
	{
		$trelis = WC()->payment_gateways->payment_gateways()['trelis'];
		$json = file_get_contents('php://input');

		$expected_signature = hash_hmac('sha256', $json,  $trelis->get_option('webhook_secret'));

		// $headers = array('Content-Type: text/html; charset=UTF-8');
		// wp_mail('ronan@trelis.com', 'Trelis payment webhook response', $json, $headers);
		// $this->custom_logs_rest('payment webhook response', $json);

		if ($expected_signature != $_SERVER["HTTP_SIGNATURE"])
			return __('Failed', 'trelis-crypto-payments');

		$data = json_decode($json);

		if ($data->mechantProductKey) {
			$meta_value = $data->mechantProductKey;
		} else {
			$meta_value = $data->subscriptionLink;
		}

		if ($data->from) {
			$customerWalletId = $data->from;
		} else {
			$customerWalletId = $data->customer;
		}

		$orders = get_posts(array(
			'post_type' => 'shop_order',
			'posts_per_page' => -1,
			'post_status' => 'any',
			'meta_key'   => '_trelis_payment_link_id',
			'meta_value' => $meta_value,
		));

		if (empty($orders))
			return __('Order not found', 'trelis-crypto-payments');


		// Get the ID of the first order in the $orders array
		$order_id = $orders[0]->ID;

		// Load the order with the specified $order_id
		$order = wc_get_order($order_id);

		// Get any subscriptions associated with the specified order ID
		$subscription = wcs_get_subscriptions_for_order($order_id);

		// If there is a subscription associated with the order...
		if ($subscription) {

			// Get the first subscription in the array of subscriptions
			$subscription = reset($subscription);

			// Get the ID of the subscription
			$subscriptionId  = $subscription->get_id();
		}

		if (strpos($data->event, 'subscription') !== false) {

			update_post_meta($subscriptionId, 'customerWalletId', $customerWalletId);

			/* This code checks the event type specified in $data and performs different actions based on the event type.

			If the event is 'subscription.charge.failed' or 'charge.failed', the function adds an order note indicating that the Trelis payment failed, saves the order, and returns 'Failed'.

			If the event is 'subscription.create.failed', the function adds an order note indicating that the subscription was not created and returns 'Subscription not created'.

			If the event is 'subscription.create.success', the function adds an order note indicating that the subscription was created and returns 'Subscription created'.

			If the event is 'subscription.charge.success', the function adds an order note indicating that the payment was complete, marks the order as paid, reduces stock levels. If there is an active cart, the cart is emptied. The function returns 'Processed'.

			If the event is 'subscription.cancellation.success', the function updates the payment method metadata for the subscription and adds an order note indicating that the subscription was cancelled successfully.

			If the event is 'subscription.cancellation.failed', the function updates the payment method metadata for the subscription and adds an order note indicating that the cancellation failed. */

			if ($data->event === 'subscription.charge.failed' || $data->event === "charge.failed") {
				$order->add_order_note(__('Trelis Payment Failed! Expected amount ', 'trelis-crypto-payments') . $data->requiredPaymentAmount . __(', attempted ', 'trelis-crypto-payments') . $data->paidAmount, true);
				$order->save();
				return __('Failed', 'trelis-crypto-payments');
			}

			if ($data->event == "subscription.create.failed") {
				$order->add_order_note(__('Subscription not created', 'trelis-crypto-payments'), true);
				return __('Subscription not created', 'trelis-crypto-payments');
			}

			if ($data->event == "subscription.create.success") {
				$order->add_order_note(__('Subscription created!', 'trelis-crypto-payments'), false);
				return __('Subscription created', 'trelis-crypto-payments');
			}

			if ($data->event == "subscription.charge.success") {
				$order->add_order_note(__('Payment complete!', 'trelis-crypto-payments'), true);
				$order->payment_complete();
				$order->reduce_order_stock();

				if (isset(WC()->cart)) {
					WC()->cart->empty_cart();
				}
				return __('Processed!', 'trelis-crypto-payments');
			}

			if ($data->event == "subscription.cancellation.success") {
				update_post_meta($subscriptionId, 'trelis_payment_method', 0);

				// Get an array of WC_Subscription objects
				$subscriptions = wcs_get_subscriptions_for_order($order_id);
				// // Not needed as handled by gateway
				// foreach ($subscriptions as $subscription_id => $subscription) {
				// 	// Change the status of the WC_Subscription object
				// 	$subscription->update_status('cancelled');
				// }

				$order->add_order_note(__('Subscription cancelled successfully !', 'trelis-crypto-payments'), true);
			}

			if ($data->event == "subscription.cancellation.failed") {
				update_post_meta($subscriptionId, 'trelis_payment_method', 0);
				$order->add_order_note(__('Subscription cancelation failed !', 'trelis-crypto-payments'), true);
			}

		} else {


			if ($order->get_status() == 'processing' || $order->get_status() == 'complete') {
				return __('Already processed', 'trelis-crypto-payments');
			}

			if ($data->event === "submission.failed" || $data->event === "charge.failed") {
				$order->add_order_note(__('Trelis Payment Failed! Expected amount ', 'trelis-crypto-payments') . $data->requiredPaymentAmount . __(', attempted ', 'trelis-crypto-payments') . $data->paidAmount, true);
				$order->save();
				return __('Failed', 'trelis-crypto-payments');
			}

			if ($data->event == "charge.failed") {
				$order->add_order_note(__('Payment not complete', 'trelis-crypto-payments'), true);
				return __('Pending', 'trelis-crypto-payments');
			}

			if ($data->event == "charge.success") {
				$order->add_order_note(__('Payment complete!', 'trelis-crypto-payments'), true);
				$order->payment_complete();
				$order->reduce_order_stock();
				// Remove cart.
				if (isset(WC()->cart)) {
					WC()->cart->empty_cart();
				}
				return __('Processed!', 'trelis-crypto-payments');
			}
			// return __('Processed!','trelis-crypto-payments');
		}
	}

	public function custom_logs_rest($apitype, $message)
	{
		if (is_array($message)) {
			$message = json_encode($message);
		}
		$upload_dir = wp_get_upload_dir();
		$file = fopen($upload_dir['basedir'] . "/trelis_logs.log", "a");
		echo fwrite($file, "\n" . date('Y-m-d h:i:s') . " :: " . $apitype . " :: " . $message);
		fclose($file);
	}

	/**
	 * This function sends a request to the Trelis API to run a subscription for the specified customer wallet IDs.
	 * If the API call is successful, it updates the subscription metadata and adds an order note.
	 *
	 * @param object $order The order object.
	 * @param string $subscriptionId The ID of the subscription to update.
	 * @param array $customerWalletIds An array of customer wallet IDs.
	 */
	public function run_subscription_api($order, $subscriptionId, $customerWalletIds)
	{
		// Set up the request arguments, including the customer wallet IDs to run the subscription for.
		$args = array(
			'headers' => array(
				'Content-Type' => "application/json"
			),
			'body' => json_encode(array(
				'customers' => $customerWalletIds
			))
		);

		// Set the Trelis API URL and add the API key and secret as query parameters.
		$apiUrl = TRELIS_API_URL . 'run-subscription?apiKey=' . $this->apiKey . '&apiSecret=' . $this->apiSecret;

		// Send the API request to run the subscription and store the response.
		$response = wp_remote_post($apiUrl, $args);

		// // Debugging code to send an email and write logs with the API response.
		// $headers = array('Content-Type: text/html; charset=UTF-8');
		// wp_mail('ronan@trelis.com', 'Trelis run subscription API', print_r($response, true), $headers);
		// $this->custom_logs('run subscription api in webhook response', $response);

		/* Check if the API request was successful.
		* If it was, update the subscription metadata and add an order note.
		* If it wasn't, display an error message.
		*/
		/*
		if (!is_wp_error($response)) {
			$body = json_decode($response['body'], true);
			if($body['data']['event'] == 'subscription.create.success')
			{
				update_post_meta( $subscriptionId, 'trelis_subscriptionLink', $body['data']['subscriptionLink'] );
				update_post_meta( $subscriptionId, 'trelis_payment_method', 1 );
				$order->add_order_note(__('Subscription Created!','trelis-crypto-payments'), true);
			}else{
				wc_add_notice(__('Subscription not complete at Trelis','trelis-crypto-payments'), 'error');
				$order->add_order_note(__('Subscription not complete at Trelis','trelis-crypto-payments'), true);
			}
			return;
		} else {
			wc_add_notice($response->get_error_message(), 'error');
			wc_add_notice(__('Connection error','trelis-crypto-payments'), 'error');
			return;
		}
		*/
	}

}
