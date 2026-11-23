<?php
/**
 * CHIP for VirtueMart - CHIP Collect API client.
 *
 * Framework-agnostic HTTP client (cURL with stream fallback) so it works in the
 * Joomla/VirtueMart context without depending on WordPress functions. Mirrors the
 * shared CHIP API spec (see ~/chip-platform/CHIP-API-SPEC.md).
 *
 * @author   CHIPAsia
 * @license  GNU/GPL v3 or later
 */

defined('_JEXEC') or die('Restricted access');

class ChipVirtueMartApi
{
	/** API base URL (no trailing slash). */
	const API_BASE = 'https://gate.chip-in.asia/api/v1';

	/** @var string */
	private $secretKey;

	/** @var string */
	private $brandId;

	/** @var int */
	private $timeout = 15;

	/** @var array<string, ChipVirtueMartApi> */
	private static $instances = array();

	/**
	 * Constructor is private: use getInstance() (singleton keyed by credentials).
	 *
	 * @param string $secretKey
	 * @param string $brandId
	 */
	private function __construct($secretKey, $brandId)
	{
		$this->secretKey = $secretKey;
		$this->brandId   = $brandId;
	}

	/**
	 * Singleton: different credentials get different instances.
	 *
	 * @param string $secretKey
	 * @param string $brandId
	 *
	 * @return ChipVirtueMartApi
	 */
	public static function getInstance($secretKey, $brandId)
	{
		$key = md5($secretKey . '|' . $brandId);

		if (!isset(self::$instances[$key]))
		{
			self::$instances[$key] = new self($secretKey, $brandId);
		}

		return self::$instances[$key];
	}

	/**
	 * Build a full API URL.
	 *
	 * @param string $path   e.g. /purchases/
	 * @param array  $params query string parameters
	 *
	 * @return string
	 */
	private function buildUrl($path, $params = array())
	{
		$url = self::API_BASE . '/' . ltrim($path, '/');

		if (!empty($params))
		{
			$url .= '?' . http_build_query($params);
		}

		return $url;
	}

	/**
	 * Perform an HTTP request (GET/POST) with a JSON body.
	 *
	 * @param string $method GET or POST
	 * @param string $path   API path
	 * @param array  $body   JSON body
	 * @param array  $query  query params (GET cache-bust `time` is added automatically)
	 *
	 * @return mixed decoded JSON (array, or raw string for e.g. /public_key/), or false
	 */
	private function request($method, $path, $body = array(), $query = array())
	{
		if ($method === 'GET')
		{
			$query['time'] = time();
		}

		$url     = $this->buildUrl($path, $query);
		$json    = json_encode($body);
		$headers = array(
			'Authorization: Bearer ' . $this->secretKey,
			'Content-Type: application/json',
			'Accept: application/json',
		);

		$response = false;
		$status   = 0;

		if (function_exists('curl_init'))
		{
			$curl = curl_init();
			curl_setopt($curl, CURLOPT_URL, $url);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, $this->timeout);
			curl_setopt($curl, CURLOPT_TIMEOUT, $this->timeout);
			curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
			curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
			curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

			if ($method === 'POST')
			{
				curl_setopt($curl, CURLOPT_POST, true);
				curl_setopt($curl, CURLOPT_POSTFIELDS, $json);
			}

			$response = curl_exec($curl);
			$status   = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
			curl_close($curl);
		}
		else
		{
			$headerString = implode("\r\n", $headers);
			$context = stream_context_create(array(
				'http' => array(
					'method'        => $method,
					'header'        => $headerString,
					'content'       => $json,
					'timeout'       => $this->timeout,
					'ignore_errors' => true,
				),
				'ssl' => array(
					'verify_peer'      => true,
					'verify_peer_name' => true,
				),
			));

			$response = @file_get_contents($url, false, $context);
			if (function_exists('http_get_last_response_headers'))
			{
				// PHP 8.5 deprecates the locally scoped $http_response_header variable.
				$responseHeaders = http_get_last_response_headers();
				$responseHeaders = is_array($responseHeaders) ? $responseHeaders : array();
			}
			else
			{
				// PHP 8.5 deprecates $http_response_header at compile time, so it is read through
				// a variable variable to keep older branches working without the notice.
				$compatHeaderName = 'http_response_header';
				$responseHeaders   = isset(${$compatHeaderName}) ? ${$compatHeaderName} : array();
			}
			foreach ($responseHeaders as $headerLine)
			{
				if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $headerLine, $matches))
				{
					$status = (int) $matches[1];
					break;
				}
			}
		}

		if ($response === false || $response === '')
		{
			return false;
		}

		$decoded = json_decode($response, true);

		if ($decoded === null)
		{
			return false;
		}

		return $decoded;
	}

	/**
	 * Create a purchase (payment).
	 *
	 * @param array $params
	 *
	 * @return array|false
	 */
	public function createPurchase($params)
	{
		return $this->request('POST', '/purchases/', $params);
	}

	/**
	 * Fetch a purchase by ID.
	 *
	 * @param string $purchaseId
	 *
	 * @return array|false
	 */
	public function getPurchase($purchaseId)
	{
		if ($purchaseId === '')
		{
			return false;
		}

		return $this->request('GET', '/purchases/' . rawurlencode($purchaseId) . '/');
	}

	/**
	 * Refund a purchase. Amount is in sen (minor units).
	 *
	 * @param string $purchaseId
	 * @param int    $amountSen
	 *
	 * @return array|false
	 */
	public function refundPurchase($purchaseId, $amountSen)
	{
		if ($purchaseId === '' || $amountSen <= 0)
		{
			return false;
		}

		return $this->request('POST', '/purchases/' . rawurlencode($purchaseId) . '/refund/', array(
			'amount' => $amountSen,
		));
	}

	/**
	 * List available payment methods for the brand.
	 * `amount` is mandatory (in sen); 1000 (RM 10) is the safe default so that
	 * all methods are returned (see CHIP-API-SPEC.md).
	 *
	 * @param int    $amountSen
	 * @param string $currency
	 * @param string $language
	 *
	 * @return array|false
	 */
	public function getPaymentMethods($amountSen = 1000, $currency = '', $language = '')
	{
		$query = array(
			'brand_id' => $this->brandId,
			'amount'   => $amountSen,
		);

		if ($currency !== '')
		{
			$query['currency'] = $currency;
		}

		if ($language !== '')
		{
			$query['language'] = $language;
		}

		return $this->request('GET', '/payment_methods/', array(), $query);
	}

	/**
	 * Resolve the configured payment method whitelist against the merchant's
	 * actual /payment_methods/ response, with preferred-method priority for the
	 * DuitNow QR and Shopee Pay groups (mirrors chip-for-woocommerce /
	 * chip-for-prestashop):
	 *
	 *   - DuitNow QR group: dnqr wins when both {duitnow_qr, dnqr} are present.
	 *   - Shopee Pay group: shopee_pay wins when both {razer_shopeepay, shopee_pay}
	 *     are present; razer_shopeepay is the fallback.
	 *
	 * @param array  $whitelist configured payment_method_whitelist
	 * @param string $currency  order currency code (e.g. 'MYR')
	 * @param int    $amount    order total in sen
	 *
	 * @return array
	 */
	public function resolvePaymentMethodGroups($whitelist, $currency, $amount)
	{
		$duitnowGroup = array('duitnow_qr', 'dnqr');
		$shopeeGroup  = array('razer_shopeepay', 'shopee_pay');
		$cardGroup    = array('visa', 'mastercard', 'maestro');

		// Card group: 'card' is an in-memory aggregator key (mirrors
		// chip-for-woocommerce). Expand it to the resolved card-network
		// identifiers and strip the aggregator key — CHIP does not recognise
		// 'card' as a payment_method_whitelist value.
		if (in_array('card', $whitelist, true))
		{
			$whitelist = array_values(array_unique(array_merge($whitelist, $cardGroup)));
		}
		$whitelist = array_values(array_diff($whitelist, array('card')));

		$hasDnqr   = count(array_intersect($whitelist, $duitnowGroup)) > 0;
		$hasShopee = count(array_intersect($whitelist, $shopeeGroup)) > 0;

		if (!$hasDnqr && !$hasShopee)
		{
			return $whitelist;
		}

		$expanded = $whitelist;

		if ($hasDnqr)
		{
			$expanded = array_values(array_unique(array_merge($expanded, $duitnowGroup)));
		}

		if ($hasShopee)
		{
			$expanded = array_values(array_unique(array_merge($expanded, $shopeeGroup)));
		}

		$response = $this->getPaymentMethods($amount, $currency, '');

		if (!is_array($response) || !isset($response['available_payment_methods']))
		{
			return $expanded;
		}

		$available = $response['available_payment_methods'];

		$resolvedDnqr   = $hasDnqr ? array_values(array_intersect($duitnowGroup, $available)) : array();
		$resolvedShopee = $hasShopee ? array_values(array_intersect($shopeeGroup, $available)) : array();

		if (in_array('dnqr', $resolvedDnqr, true))
		{
			$resolvedDnqr = array_values(array_diff($resolvedDnqr, array('duitnow_qr')));
		}

		if (in_array('shopee_pay', $resolvedShopee, true))
		{
			$resolvedShopee = array_values(array_diff($resolvedShopee, array('razer_shopeepay')));
		}

		$allGroups = array_merge($duitnowGroup, $shopeeGroup);
		$final     = array_values(array_diff($expanded, $allGroups));

		return array_merge($final, $resolvedDnqr, $resolvedShopee);
	}

	/**
	 * Fetch the public key used to verify webhook signatures.
	 * Result is cached in Joomla component params/options by the caller.
	 *
	 * @return string|false PEM public key
	 */
	public function getPublicKey()
	{
		$response = $this->request('GET', '/public_key/');

		if ($response === false || $response === null)
		{
			return false;
		}

		// The endpoint returns the PEM key either as a raw JSON string
		// ("-----BEGIN PUBLIC KEY-----\n...") or as {"key": "..."}.
		$key = '';

		if (is_array($response))
		{
			$key = isset($response['key']) ? (string) $response['key'] : '';
		}
		elseif (is_string($response))
		{
			$key = $response;
		}

		if ($key === '')
		{
			return false;
		}

		// Normalize escaped newlines.
		return str_replace(array("\\r\\n", "\\n"), array("\r\n", "\n"), $key);
	}

	/**
	 * Verify the X-Signature header against the raw callback body.
	 *
	 * @param string $content   raw request body
	 * @param string $signature base64 encoded signature from X-Signature header
	 * @param string $publicKey PEM public key
	 *
	 * @return bool
	 */
	public function verifySignature($content, $signature, $publicKey)
	{
		if ($publicKey === '' || $signature === '')
		{
			return false;
		}

		$decoded = base64_decode($signature);

		if ($decoded === false || $decoded === '')
		{
			return false;
		}

		return openssl_verify($content, $decoded, $publicKey, 'sha256WithRSAEncryption') === 1;
	}
}
