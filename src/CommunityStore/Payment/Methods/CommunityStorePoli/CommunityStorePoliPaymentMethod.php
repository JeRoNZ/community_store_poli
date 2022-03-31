<?php

namespace Concrete\Package\CommunityStorePoli\Src\CommunityStore\Payment\Methods\CommunityStorePoli;

/*
 * Author: Jeremy Rogers infoatjero.co.nz
 * License: MIT
 */

use Concrete\Core\Error\ErrorList\ErrorList;
use Concrete\Core\Http\Response;
use Concrete\Core\Support\Facade\Application;
use Core;
use IPLib\Address\AddressInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use URL;
use Config;

use \Concrete\Package\CommunityStore\Src\CommunityStore\Payment\Method as StorePaymentMethod;
use \Concrete\Package\CommunityStore\Src\CommunityStore\Order\Order as StoreOrder;
use Concrete\Core\Logging\LoggerFactory;

class CommunityStorePoliPaymentMethod extends StorePaymentMethod {
	/* @var $logger \Monolog\Logger */
	private $logger;

	public function getName () {
		return 'POLi';
	}

	public function isExternal () {
		return true;
	}

	public function dashboardForm () {
		$this->set('livetapiurl', Config::get('community_store.poli.liveapiurl'));
		$this->set('livemerchantcode', Config::get('community_store.poli.livemerchantcode'));
		$this->set('liveauthcode', Config::get('community_store.poli.liveauthcode'));
		$this->set('testmode', Config::get('community_store.poli.testmode'));
		$this->set('testapiurl', Config::get('community_store.poli.testapiurl'));
		$this->set('testmerchantcode', Config::get('community_store.poli.testmerchantcode'));
		$this->set('testauthcode', Config::get('community_store.poli.testauthcode'));
		$this->set('currency', Config::get('community_store.poli.currency'));
		$this->set('maxattempts', Config::get('community_store.poli.maxattempts'));
		$this->set('debug', Config::get('community_store.poli.debug'));
		$currencies = array(
//			'AUD' => 'Australian Dollar',
			'NZD' => 'New Zealand Dollar',
		);
		$this->set('currencies', $currencies);
		$app = Application::getFacadeApplication();
		$this->set('form', $app->make('helper/form'));
	}


	private function getAuth () {
		if (Config::get('community_store.poli.testmode')) {
			return base64_encode(Config::get('community_store.poli.testmerchantcode') . ':' . Config::get('community_store.poli.testauthcode'));
		}

		return base64_encode(Config::get('community_store.poli.livemerchantcode') . ':' . Config::get('community_store.poli.liveauthcode'));
	}

	private function getURL () {
		if (Config::get('community_store.poli.testmode')) {
			$url = Config::get('community_store.poli.testapiurl');
		} else {
			$url = Config::get('community_store.poli.liveapiurl');
		}

		// Remove trailing / if it's there, so we do not end up with two later
		return trim($url, '/');
	}

	public function save (array $data = []) {
		Config::save('community_store.poli.testapiurl', $data['politestapiurl']);
		Config::save('community_store.poli.currency', $data['policurrency']);
		Config::save('community_store.poli.testmerchantcode', $data['politestmerchantcode']);
		Config::save('community_store.poli.testauthcode', $data['politestauthcode']);
		Config::save('community_store.poli.debug', ($data['polidebug'] ? 1 : 0));
		Config::save('community_store.poli.liveapiurl', $data['poliliveapiurl']);
		Config::save('community_store.poli.livemerchantcode', $data['polilivemerchantcode']);
		Config::save('community_store.poli.liveauthcode', $data['poliliveauthcode']);
		Config::save('community_store.poli.testmode', ($data['politestmode'] ? 1 : 0));
		Config::save('community_store.poli.maxattempts', ($data['polimaxattempts'] ? (int) $data['polimaxattempts'] : 20));
	}


	public function validate ($args, $e) {
		$pm = StorePaymentMethod::getByHandle('community_store_poli');
		if ($args['paymentMethodEnabled'][$pm->getID()] == 1) {
			if ($args['politestmode']) {
				if ($args['politestmerchantcode'] === '') {
					$e->add(t('Test Merchant Code must be set'));
				}
				if ($args['politestauthcode'] === '') {
					$e->add(t('Test Authorisation Code must be set'));
				}
				if ($args['politestapiurl'] === '') {
					$e->add(t('Test URL must be set'));
				}
			} else {
				if ($args['polilivemerchantcode'] === '') {
					$e->add(t('Live Merchant Code must be set'));
				}
				if ($args['poliliveauthcode'] === '') {
					$e->add(t('Live Authorisation Code must be set'));
				}
				if ($args['poliliveapiurl'] === '') {
					$e->add(t('Live URL must be set'));
				}
			}
		}

		return $e;

	}


	private function log ($message, $force = false) {
		if (!$force) {
			if (!Config::get('community_store.poli.debug')) {
				return false;
			}
		}
		if (!$this->logger) {
			$app = Application::getFacadeApplication();
			$this->logger = $app->make(LoggerFactory::class)->createLogger('poli');
		}
		if ($force) {
			$this->logger->addError($message);
		} else {
			$this->logger->addDebug($message);
		}

		return true;

	}


	public function getAction () {
		// This function is called by the checkout::external() method, which does not listen for a response object
		$app = Application::getFacadeApplication();

		$session = $app->make('session');
		/* @var $session \Symfony\Component\HttpFoundation\Session\Session */

		$oid = $session->get('orderID');
		$order = StoreOrder::getByID($oid);
		if (!$order) {
			$this->log(t('Unable to find the order %s', $oid), true);
			throw new \Exception(t('Unable to find the order'));
		}
		/* @var $order StoreOrder */

		// Prevent huge numbers of checkout requests from compromised accounts
		$POLiAttempts = $session->get('POLiAttempts') ?: 0;

		/** @var $ip  AddressInterface */
		$ip = $app->make(AddressInterface::class);

		$maxAttempts = Config::get('community_store.poli.maxattempts') ?: 20;

		if (++$POLiAttempts > $maxAttempts) {
			$this->log(t('More than %s checkout attempts from IP %s', $maxAttempts, $ip), true);
			/* Ban the IP */
			$app->make('failed_login')->addToBlacklistForThresholdReached();

			throw new \Exception(t('Payment attempt limit exceeded'));
		}

		$session->set('POLiAttempts', $POLiAttempts);

		if ($app->make('failed_login')->isBlacklisted()) {
			$this->log(t('Checkout attempt from banned IP %s', $ip), true);

			throw new \Exception(t('Payment attempt limit exceeded'));
		}

		// https://www.polipayments.com/InitiateTransaction
		$payload = [
			'Amount' => number_format($order->getTotal(), 2, '.', ''),
			'CurrencyCode' => Config::get('community_store.poli.currency'),
			'MerchantReference' => t('Order %s', sprintf('%06d', $oid)),
			'MerchantReferenceFormat' => '1', // free format
			'MerchantData' => $oid,
			'MerchantHomepageURL' => (string) URL::to('/'),
			'SuccessURL' => (string) URL::to('/checkout/polisuccess'),
			'FailureURL' => (string) URL::to('/checkout/polifail'),
			'CancellationURL' => (string) URL::to('/checkout'),
			'NotificationURL' => (string) URL::to('/checkout/polinudge'),
		];

		$json = json_encode($payload);
		$this->log(var_export($json, true));

		$auth = $this->getAuth();

		$headers = ['Content-Type: application/json', 'Authorization: Basic ' . $auth];

		$url = $this->getURL() . '/api/v2/Transaction/Initiate';
		$this->log(var_export($url, true));

		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		$response = curl_exec($ch);
		$err = curl_errno($ch);
		$errormess = curl_error($ch);
		$status = curl_getinfo($ch);

		curl_close($ch);

		if ($err || $status['http_code'] !== 200) {
			$error = new ErrorList();
			$error->add(t('Unable to initiate transactions with POLi'));
			$this->flash('error', $error);
			$this->log(t('Unable to intiate transaction with POLi: error %s %s, status %s, response %s', $err, $errormess, $status, $response), true);

			// Not returning a response object because nothing is handling it.
			header('Location: ' . url::to('/checkout'));
			die();

		}

		$json = json_decode($response, true);
		if (!$json) {
			$error = new ErrorList();
			$this->log(t('Unable to decode transaction response from POLi: response %s', $response), true);
			$this->flash('error', $error);

			header('Location: ' . url::to('/checkout'));
			die();
		}

		if ($json['Success'] !== true) {
			$error = new ErrorList();
			$error->add(t('Error initiating transaction with POLi'));
			$this->flash('error', $error);
			$this->log(t('Error initiating transaction with POLi: response %s', $response), true);

			header('Location: ' . url::to('/checkout'));
			die();
		}

		return $json['NavigateURL'];
	}

	private function getTransaction ($token) {
		$headers = ['Authorization: Basic ' . $this->getAuth()];
		$url = $this->getURL() . '/api/v2/Transaction/GetTransaction?token=' . urlencode($token);
		$this->log(t('Performing getTransaction for token %s', $token));

		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_POST, 0);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		$response = curl_exec($ch);

		$err = curl_errno($ch);
		$errormess = curl_error($ch);
		$status = curl_getinfo($ch);

		curl_close($ch);

		if ($err || $status['http_code'] !== 200) {
			$this->log(t('Error performing GetTransaction for token %s, url=%s, err=%s, error=%s, status=%s, response=%s',
				$token, $url, $err, $errormess, $status, var_export($response, true)), true);

			return new Response('Fail', 500);
		}

		$json = json_decode($response, true);
		if (!$json) {
			$this->log(t('Cannot parse JSON from GetTransaction for token %s, url=%s, err=%s, error=%s, status=%s, response=%s',
				$token, $url, $err, $errormess, $status, var_export($response, true)), true);

			return new Response('Fail', 500);
		}

		if ($json['ErrorCode']) {
			$this->log(t('Transactions error %s %s from GetTransaction for token %s, response=%s',
				$json['ErrorCode'], $json['ErrorMessage'], $token, var_export($response, true)), true);
		}

		return $json;

	}


	public function PoliNudge () {
		/* From the POLi Docs:
		 * Note: The nudge is only a notification that a transaction has reached an end (terminal) state.
		 * This is not a notification indicating that funds will be received.
		 * The information you receive in your GETTransaction call should update your system in the correct way.
		 * Daily reconciliation should then be performed to ensure funds are received before issuing the good/service purchased.
		 *
		 * Important: For security reasons, the POLi Nudge contains no detailed information about the transaction
		 * and alone cannot be used as confirmation that a payment was successful; it only indicates that
		 * the transaction process has now ended.
		 */

		/*
		 * If behind a firewall, the nudge IPs need to be whitelisted
		 * https://www.polipay.co.nz/support/what-poli-server-ips-can-i-whitelist/
		 *
		 * POLi GETTransaction IP Address: 125.239.19.83
		 * POLi Nudge IP Addresses: 52.64.125.233, 54.153.153.81 (incoming to your server)
		 *
		 * Not documented, but test IPs appear to include:
		 * POLi GETTransaction IP Address: poliapi.uat1.paywithpoli.com 3.105.25.15
		 * POLi Nudge IP Addresses: 52.64.130.169 13.55.43.144 (incoming to your server)
		 *
		 */
		if (!$this->request->isMethod('POST')) {
			$this->log(t('Received a nudge request which wasn\'t a POST'), true);

			return new Response('Fail', 400);
		}

		$token = $this->request->get('Token');
		if (!$token) {
			$this->log(t('No token provided to POLi nudge'), true);

			return new Response('Fail', 400);

		}

		$json = $this->getTransaction($token);
		if ($json instanceof Response) {
			return $json;
		}

		if ($json['ErrorCode']) {
			return new Response('OK', 200);
		}

		$complete = $this->completeOrder($json, $token, 'POLiNudge');
		if ($complete instanceof Response) {
			return $complete;
		}

		return new Response('OK', 200);
	}


	private function completeOrder ($json, $token, $method) {
		$oid = (int) $json['MerchantData'];

		/** @var StoreOrder $order */
		$order = StoreOrder::getByID($oid);
		if (!$order) {
			$this->log(t('Fatal: %s: no such order %s for token %s', $method, $oid, $token), true);

			return new Response('Fail', 500);
		}

		// https://www.polipayments.com/TransactionStatus
		if ($json['TransactionStatus'] === 'Completed') {
			if (!$order->getTransactionReference()) {
				$this->log(t('%s: Completing order %s because it does not have a transaction reference set. Ref: %s', $method, $oid, (string) $json['TransactionRefNo']));
				$order->completeOrder((string) $json['TransactionRefNo']);
			} else {
				$this->log(t('%s: NOT Completing order %s because it already has a transaction reference set', $method, $oid));
			}
		} else {
			$this->log(t('%s: Order %s not complete because transaction status is %s, token %s', $method, $oid, $json['TransactionStatus'], $token));
		}
	}


	public function PoliCancel () {
		// Don't really care about the token here, because the user cancelled the transaction,
		// so we send them back to the checkout, but flash them first.
		$error = new ErrorList();
		$error->add(t('Payment cancelled'));
		$this->flash('error', $error);

		$this->log(t('PoliCancel: Redirecting to /checkout'));

		return new RedirectResponse(\URL::to('/checkout'));
	}


	public function PoliFail () {
		// The transaction failed.
		// Retrieve the transaction to find out why.
		$error = new ErrorList();

		$token = $this->request->get('token');
		if ($token) {
			$json = $this->getTransaction($token);
			if (!$token instanceof Response) {
				$error->add($json['ErrorMessage']);
			}
		}
		if (!$error->has()) {
			$error->add(t('Payment failed'));
		}

		$this->flash('error', $error);

		$this->log(t('PoliFail: Redirecting to /checkout/failed'));

		return new RedirectResponse(\URL::to('/checkout/failed'));
	}


	public function PoliSuccess () {
		// The transaction succeeded.
		// Retrieve the transaction and complete the order
		// In most cases, the "Nudge" will have beaten us to it,
		// So this step is really just a safety net
		$token = $this->request->get('token');
		$this->log(t('PoliSuccess: token %s', $token));

		if ($token) {
			$json = $this->getTransaction($token);
			if ($json['ErrorCode']) {
				$this->log(t('Transaction failed for token %s, response=', $token, $json), true);
				$error = new ErrorList();
				$error->add('Transaction failed');
				$error->add($json['ErrorMessage']);
				$this->flash('error', $error);

				return new RedirectResponse(\URL::to('/checkout/failed'));
			}

			$complete = $this->completeOrder($json, $token, 'PoliSuccess');
			if ($complete instanceof Response) { // Only happens if we can't find the order, which should never happen
				return $complete;
			}
		}

		// remove checkout attempt counter as they're likely legitimate if they made it.
		$app = Application::getFacadeApplication();
		/* @var $session \Symfony\Component\HttpFoundation\Session\Session */
		$session = $app->make('session');
		$session->remove('POLiAttempts');

		$this->log(t('PoliSuccess: Redirecting to /checkout/complete'));

		// Return to the regular checkout complete page.
		return new RedirectResponse(\URL::to('/checkout/complete'));
	}
}