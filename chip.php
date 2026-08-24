<?php
/**
 * CHIP for VirtueMart - Payment plugin.
 *
 * Accept payments via the CHIP Collect API (FPX, DuitNow QR, cards, e-wallets
 * and more). Hosted redirect flow: the plugin creates a CHIP purchase and
 * redirects the customer to the CHIP checkout page.
 *
 * @author   CHIPAsia
 * @package  VirtueMart
 * @subpackage Payment
 * @copyright Copyright (c) 2026 CHIPAsia
 * @license  http://www.gnu.org/licenses/gpl-3.0.html GNU/GPL v3 or later
 */

defined('_JEXEC') or die('Restricted access');

// VirtueMart 4.x (released) declares vmPSPlugin / VirtueMartCart /
// VirtueMartModelOrders in the GLOBAL namespace (no `namespace VirtueMart;`).
// The SVN trunk (unreleased) has migrated them to the VirtueMart namespace, so
// do NOT add `use VirtueMart\...` here — it breaks on the released 4.8.0.

class plgVmPaymentChip extends vmPSPlugin
{
	/** @var string plugin element (matches the folder name / XML plugin attribute) */
	protected $paymentElement = 'chip';

	/**
	 * Constructor.
	 *
	 * @param object $subject
	 * @param array  $config
	 */
	public function __construct(&$subject, $config)
	{
		parent::__construct($subject, $config);

		// Load the CHIP API client.
		if (!class_exists('ChipVirtueMartApi'))
		{
			$path = VMPATH_ROOT . '/plugins/vmpayment/chip/helpers/chip_api.php';
			if (file_exists($path))
			{
				require_once $path;
			}
		}

		$this->_loggable  = true;
		$this->tableFields = array_keys($this->getTableSQLFields());
		$this->_tablepkey = 'id';
		$this->_tableId   = 'id';

		// Config vars are read from chip.xml; register the core set (fees,
		// restrictions, currencies) on top.
		$varsToPush = $this->getVarsToPush();
		$this->addVarsToPushCore($varsToPush, 1);
		$this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
		$this->setConvertable(array('min_amount', 'max_amount', 'cost_per_transaction', 'cost_min_transaction'));
	}

	/**
	 * Fields for the plugin-specific data table.
	 *
	 * @return array
	 */
	public function getTableSQLFields()
	{
		return array(
			'id'                          => 'int(1) UNSIGNED NOT NULL AUTO_INCREMENT',
			'virtuemart_order_id'         => 'int(1) UNSIGNED',
			'order_number'                => 'char(64)',
			'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
			'payment_name'                => 'varchar(5000)',
			'payment_order_total'         => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
			'payment_currency'            => 'char(3)',
			'email_currency'              => 'char(3)',
			'chip_purchase_id'            => 'char(64)',
			'chip_status'                 => 'char(32)',
			'cost_per_transaction'        => 'decimal(10,2)',
			'cost_min_transaction'        => 'decimal(10,2)',
			'cost_percent_total'          => 'decimal(10,2)',
			'tax_id'                      => 'smallint(1)',
		);
	}

	/**
	 * Return the CHIP API client for the configured credentials.
	 *
	 * @param object $method payment method config object
	 *
	 * @return ChipVirtueMartApi
	 */
	private function getApi($method)
	{
		$secretKey = isset($method->secret_key) ? trim((string) $method->secret_key) : '';
		$brandId   = isset($method->brand_id) ? trim((string) $method->brand_id) : '';

		return ChipVirtueMartApi::getInstance($secretKey, $brandId);
	}

	/**
	 * Build the CHIP purchase params.
	 *
	 * @param object $method
	 * @param object $order
	 * @param array  $totalInPaymentCurrency
	 * @param string $currencyCode3
	 * @param string $notificationUrl webhook / success_callback URL
	 * @param string $responseUrl    success_redirect URL
	 * @param string $cancelUrl      cancel_redirect URL
	 *
	 * @return array
	 */
	private function buildPurchaseParams($method, $order, $totalInPaymentCurrency, $currencyCode3, $notificationUrl, $responseUrl, $cancelUrl)
	{
		$bt = $order['details']['BT'];

		$lang = JFactory::getLanguage();
		$tag  = substr($lang->get('tag'), 0, 2);
		if ($tag === '')
		{
			$tag = 'en';
		}

		$totalInSen = (int) round((float) $totalInPaymentCurrency['value'] * 100);

		$params = array(
			'success_callback' => $notificationUrl,
			'success_redirect' => $responseUrl,
			'failure_redirect' => $responseUrl,
			'cancel_redirect'  => $cancelUrl,
			'creator_agent'    => 'VirtueMart: ' . $this->getVersion(),
			'reference'        => (string) $bt->order_number,
			'platform'         => 'api',
			'purchase'         => array(
				'total_override' => $totalInSen,
				'due_strict'     => !empty($method->due_strict),
				'timezone'       => isset($method->timezone) ? $method->timezone : 'Asia/Kuala_Lumpur',
				'currency'       => $currencyCode3,
				'language'       => $tag,
				'products'       => $this->buildProducts($order, $totalInSen),
			),
			'brand_id'         => isset($method->brand_id) ? $method->brand_id : '',
			'client'           => $this->buildClient($bt),
		);

		$whitelist = $this->getConfiguredWhitelist($method);

		if (!empty($whitelist))
		{
			$api = $this->getApi($method);
			$params['payment_method_whitelist'] = $api->resolvePaymentMethodGroups(
				$whitelist,
				$currencyCode3,
				$totalInSen
			);
		}

		return $params;
	}

	/**
	 * Build the products array (sen prices) from the order items.
	 *
	 * @param object $order
	 * @param int    $totalInSen
	 *
	 * @return array
	 */
	private function buildProducts($order, $totalInSen)
	{
		$products = array();
		$useTotalOverride = false;

		if (!empty($order['items']))
		{
			foreach ($order['items'] as $item)
			{
				$name = isset($item->order_item_name) ? (string) $item->order_item_name : 'Product';
				$qty  = isset($item->product_quantity) ? max(1, (int) $item->product_quantity) : 1;
				$lineTotal = isset($item->product_item_price) ? (float) $item->product_item_price : 0.0;
				$unitPrice = (int) round($lineTotal * 100 / $qty);

				if ($unitPrice <= 0 && $totalInSen > 0)
				{
					$useTotalOverride = true;
					break;
				}

				$products[] = array(
					'name'     => substr($name, 0, 256),
					'price'    => $unitPrice,
					'quantity' => $qty,
				);
			}
		}

		if ($useTotalOverride || empty($products))
		{
			$products = array(
				array(
					'name'  => 'Order ' . (isset($order['details']['BT']->order_number) ? $order['details']['BT']->order_number : ''),
					'price' => $totalInSen,
					'quantity' => 1,
				),
			);
		}

		return $products;
	}

	/**
	 * Build the client array from the billing address.
	 *
	 * @param object $bt billing address object
	 *
	 * @return array
	 */
	private function buildClient($bt)
	{
		$client = array(
			'email'         => isset($bt->email) ? substr((string) $bt->email, 0, 254) : '',
			'full_name'     => substr(trim($bt->first_name . ' ' . $bt->last_name), 0, 128),
			'street_address' => substr(trim($bt->address_1 . ' ' . (isset($bt->address_2) ? $bt->address_2 : '')), 0, 128),
			'city'          => isset($bt->city) ? substr((string) $bt->city, 0, 128) : '',
			'zip_code'      => isset($bt->zip) ? substr((string) $bt->zip, 0, 32) : '',
		);

		if (!empty($bt->phone_1))
		{
			$client['phone'] = substr((string) $bt->phone_1, 0, 32);
		}

		if (!empty($bt->virtuemart_country_id))
		{
			$client['country'] = substr(ShopFunctions::getCountryByID($bt->virtuemart_country_id, 'country_2_code'), 0, 2);
		}

		if (!empty($bt->virtuemart_state_id))
		{
			$client['state'] = substr(ShopFunctions::getStateByID($bt->virtuemart_state_id, 'state_2_code'), 0, 128);
		}

		// Drop empty fields (mirrors WooCommerce/PrestaShop).
		foreach ($client as $key => $value)
		{
			if ($value === '' || $value === null)
			{
				unset($client[$key]);
			}
		}

		return $client;
	}

	/**
	 * Resolve the configured payment method whitelist (comma-separated string
	 * in method params) into an array of identifiers.
	 *
	 * @param object $method
	 *
	 * @return array
	 */
	private function getConfiguredWhitelist($method)
	{
		$raw = isset($method->payment_method_whitelist) ? trim((string) $method->payment_method_whitelist) : '';

		if ($raw === '')
		{
			return array();
		}

		$items = array_map('trim', explode(',', $raw));

		return array_values(array_filter($items));
	}

	/**
	 * Current plugin version (for the creator_agent header).
	 *
	 * @return string
	 */
	private function getVersion()
	{
		return '1.0.0';
	}

	/**
	 * Build the response/notification URLs for the current order.
	 *
	 * @param object $order
	 *
	 * @return array{notification: string, response: string, cancel: string}
	 */
	private function buildUrls($order)
	{
		$bt = $order['details']['BT'];
		$root = JURI::root();
		$itemId = vRequest::getInt('Itemid', 0);
		$lang = vRequest::getCmd('lang', '');

		$notificationUrl = $root . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification&tmpl=component&lang=' . $lang;
		$responseUrl = $root . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&on=' . $bt->order_number . '&pm=' . $bt->virtuemart_paymentmethod_id . '&Itemid=' . $itemId . '&lang=' . $lang;
		$cancelUrl = $root . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginUserPaymentCancel&on=' . $bt->order_number . '&pm=' . $bt->virtuemart_paymentmethod_id . '&Itemid=' . $itemId . '&lang=' . $lang;

		return array(
			'notification' => $notificationUrl,
			'response'     => $responseUrl,
			'cancel'       => $cancelUrl,
		);
	}

	/**
	 * Create the CHIP purchase and redirect the customer.
	 *
	 * @param object $cart
	 * @param object $order
	 *
	 * @return bool|null
	 */
	public function plgVmConfirmedOrder($cart, $order)
	{
		if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id)))
		{
			return null;
		}

		if (!$this->selectedThisElement($method->payment_element))
		{
			return false;
		}

		vmLanguage::loadJLang('com_virtuemart', true);
		vmLanguage::loadJLang('com_virtuemart_orders', true);

		$this->getPaymentCurrency($method, $order['details']['BT']->payment_currency_id);
		$currencyCode3 = ShopFunctions::getCurrencyByID($method->payment_currency, 'currency_code_3');
		$emailCurrency = $this->getEmailCurrency($method);

		$totalInPaymentCurrency = vmPSPlugin::getAmountInCurrency($order['details']['BT']->order_total, $method->payment_currency);

		if ((float) $totalInPaymentCurrency['value'] <= 0)
		{
			vmInfo(vmText::_('VMPAYMENT_CHIP_PAYMENT_AMOUNT_INCORRECT'));

			return false;
		}

		if (empty($method->secret_key) || empty($method->brand_id))
		{
			vmInfo(vmText::_('VMPAYMENT_CHIP_CONFIG_NOT_SET'));

			return false;
		}

		// Store internal data before the API call so the notification/response
		// handlers can find the order even if the customer never returns.
		$dbValues = array();
		$dbValues['order_number'] = $order['details']['BT']->order_number;
		$dbValues['payment_name'] = $this->renderPluginName($method);
		$dbValues['virtuemart_paymentmethod_id'] = (int) $order['details']['BT']->virtuemart_paymentmethod_id;
		$dbValues['cost_per_transaction'] = $method->cost_per_transaction;
		$dbValues['cost_min_transaction'] = $method->cost_min_transaction;
		$dbValues['cost_percent_total'] = $method->cost_percent_total;
		$dbValues['payment_currency'] = $currencyCode3;
		$dbValues['email_currency'] = $emailCurrency;
		$dbValues['payment_order_total'] = $totalInPaymentCurrency['value'];
		$dbValues['tax_id'] = $method->tax_id;

		$this->storePSPluginInternalData($dbValues);

		$urls = $this->buildUrls($order);

		$api = $this->getApi($method);
		$params = $this->buildPurchaseParams(
			$method,
			$order,
			$totalInPaymentCurrency,
			$currencyCode3,
			$urls['notification'],
			$urls['response'],
			$urls['cancel']
		);

		$purchase = $api->createPurchase($params);

		if (!is_array($purchase) || empty($purchase['id']) || empty($purchase['checkout_url']))
		{
			$this->logInfo('CHIP createPurchase failed for order ' . $order['details']['BT']->order_number, 'ERROR');
			vmInfo(vmText::_('VMPAYMENT_CHIP_PURCHASE_FAILED'));

			return false;
		}

		// Persist the CHIP purchase id.
		$purchaseId = (string) $purchase['id'];
		$virtuemartOrderId = (int) $order['details']['BT']->virtuemart_order_id;

		if ($virtuemartOrderId)
		{
			$this->updateInternalData($virtuemartOrderId, array('chip_purchase_id' => $purchaseId, 'chip_status' => 'created'));
		}

		$this->logInfo('CHIP purchase ' . $purchaseId . ' created for order ' . $order['details']['BT']->order_number, 'message');

		// Redirect to CHIP checkout.
		$application = JFactory::getApplication();
		$application->redirect((string) $purchase['checkout_url']);

		return true;
	}

	/**
	 * Update a column in the plugin internal data table by order id.
	 *
	 * @param int    $virtuemartOrderId
	 * @param array  $values column => value
	 *
	 * @return void
	 */
	private function updateInternalData($virtuemartOrderId, $values)
	{
		if (empty($values))
		{
			return;
		}

		$db = JFactory::getDbo();
		$set = array();
		foreach ($values as $column => $value)
		{
			$set[] = '`' . $column . '` = ' . $db->quote($value);
		}

		$query = 'UPDATE `' . $this->_tablename . '` SET ' . implode(', ', $set)
			. ' WHERE `virtuemart_order_id` = ' . (int) $virtuemartOrderId;

		$db->setQuery($query);

		try
		{
			$db->execute();
		}
		catch (Exception $e)
		{
			$this->logInfo('updateInternalData failed: ' . $e->getMessage(), 'ERROR');
		}
	}

	/**
	 * Render the payment response table shown on the thank-you page.
	 *
	 * @param object $paymentTable
	 * @param string $paymentName
	 *
	 * @return string
	 */
	public function _getPaymentResponseHtml($paymentTable, $paymentName)
	{
		vmLanguage::loadJLang('com_virtuemart');

		$html = '<table>' . "\n";
		$html .= $this->getHtmlRow('COM_VIRTUEMART_PAYMENT_NAME', $paymentName);
		if (!empty($paymentTable))
		{
			$html .= $this->getHtmlRow(vmText::_('VMPAYMENT_CHIP_ORDER_NUMBER'), $paymentTable->order_number);
		}
		$html .= '</table>' . "\n";

		return $html;
	}

	/**
	 * Webhook / payment notification handler (CHIP success_callback).
	 *
	 * @return bool|null
	 */
	public function plgVmOnPaymentNotification()
	{
		if (!class_exists('VirtueMartModelOrders'))
		{
			require VMPATH_ADMIN . DS . 'models' . DS . 'orders.php';
		}

		$content = file_get_contents('php://input');
		if ($content === false)
		{
			$content = '';
		}
		$signature = isset($_SERVER['HTTP_X_SIGNATURE']) ? (string) $_SERVER['HTTP_X_SIGNATURE'] : '';

		$payment = null;
		$paymentId = '';

		// Attempt to locate the purchase id from the body first.
		if ($content !== '')
		{
			$body = json_decode($content, true);
			if (is_array($body) && !empty($body['id']))
			{
				$paymentId = (string) $body['id'];
			}
		}

		// Fallback: locate by order_number query param (not standard for CHIP but harmless).
		if ($paymentId === '')
		{
			$orderNumber = vRequest::getString('on', '');
			if ($orderNumber !== '')
			{
				$virtuemartOrderId = VirtueMartModelOrders::getOrderIdByOrderNumber($orderNumber);
				if ($virtuemartOrderId && ($data = $this->getDataByOrderId($virtuemartOrderId)))
				{
					$paymentId = isset($data->chip_purchase_id) ? $data->chip_purchase_id : '';
				}
			}
		}

		if ($paymentId === '')
		{
			return null;
		}

		$method = $this->getMethodByPurchaseId($paymentId);
		if (!$method)
		{
			return null;
		}

		$api = $this->getApi($method);

		// Verify signature when available.
		$signatureOk = false;
		if ($signature !== '')
		{
			$publicKey = $api->getPublicKey();
			$signatureOk = ($publicKey !== false && $api->verifySignature($content, $signature, $publicKey));
		}

		if ($signature !== '' && !$signatureOk)
		{
			$this->logInfo('CHIP notification signature verification failed for ' . $paymentId, 'ERROR');
			// Fall back to API status check (do not trust unsigned payload).
			$payment = $api->getPurchase($paymentId);
		}
		elseif ($content !== '')
		{
			$payment = json_decode($content, true);
		}

		// Last resort: fetch status from the API.
		if (!is_array($payment) || empty($payment['id']))
		{
			$payment = $api->getPurchase($paymentId);
		}

		if (!is_array($payment) || empty($payment['id']))
		{
			return null;
		}

		$this->processPaymentStatus($payment, $method);

		return true;
	}

	/**
	 * Find the payment method config object that owns a given CHIP purchase id.
	 *
	 * @param string $purchaseId
	 *
	 * @return object|false
	 */
	private function getMethodByPurchaseId($purchaseId)
	{
		$db = JFactory::getDbo();
		$query = 'SELECT `virtuemart_paymentmethod_id` FROM `' . $this->_tablename
			. '` WHERE `chip_purchase_id` = ' . $db->quote($purchaseId) . ' LIMIT 1';
		$db->setQuery($query);
		$methodId = $db->loadResult();

		if (!$methodId)
		{
			return false;
		}

		return $this->getVmPluginMethod((int) $methodId);
	}

	/**
	 * Shared status -> order status mapping.
	 *
	 * @param array  $payment CHIP purchase object
	 * @param object $method
	 *
	 * @return void
	 */
	private function processPaymentStatus($payment, $method)
	{
		$purchaseId = (string) $payment['id'];
		$status = isset($payment['status']) ? strtolower((string) $payment['status']) : '';

		$reference = isset($payment['reference']) ? (string) $payment['reference'] : '';
		if ($reference === '' && isset($payment['purchase']['reference']))
		{
			$reference = (string) $payment['purchase']['reference'];
		}

		if ($reference === '')
		{
			return;
		}

		$virtuemartOrderId = VirtueMartModelOrders::getOrderIdByOrderNumber($reference);
		if (!$virtuemartOrderId)
		{
			return;
		}

		$modelOrder = VmModel::getModel('orders');
		$order = array();
		$order['customer_notified'] = 0;
		$order['comments'] = 'CHIP payment ' . $purchaseId . ' status: ' . $status;

		switch ($status)
		{
			case 'paid':
				$order['order_status'] = isset($method->status_success) ? $method->status_success : 'C';
				$order['customer_notified'] = 1;
				$order['comments'] = vmText::sprintf('VMPAYMENT_CHIP_STATUS_PAID', $purchaseId);
				break;
			case 'pending':
				$order['order_status'] = isset($method->status_pending) ? $method->status_pending : 'P';
				break;
			case 'refunded':
				$order['order_status'] = isset($method->status_refunded) ? $method->status_refunded : 'R';
				break;
			case 'expired':
			case 'cancelled':
			case 'failed':
			default:
				$order['order_status'] = isset($method->status_canceled) ? $method->status_canceled : 'X';
				break;
		}

		$modelOrder->updateStatusForOneOrder($virtuemartOrderId, $order, true);

		// Record the status in the internal table.
		$this->updateInternalData($virtuemartOrderId, array('chip_status' => $status));

		// Clear the cart once the payment is final.
		if ($status === 'paid')
		{
			$this->emptyCart(null, $reference);
		}

		$this->logInfo('CHIP status ' . $status . ' applied to order ' . $reference, 'message');
	}

	/**
	 * Customer redirect handler (CHIP success_redirect / failure_redirect).
	 *
	 * The released VirtueMart 4.8.0 vmplg controller triggers this event with
	 * TWO by-reference params: `$html` (the payment details table) and
	 * `$paymentResponse` (the headline message, defaulting to
	 * COM_VIRTUEMART_CART_THANKYOU). We must set `$paymentResponse` to a
	 * success OR failure message based on the actual CHIP status, otherwise the
	 * customer always sees "Thank you for your Order!" even when the payment
	 * failed.
	 *
	 * @param string $html
	 * @param string $paymentResponse
	 *
	 * @return bool|null
	 */
	public function plgVmOnPaymentResponseReceived(&$html, &$paymentResponse = null)
	{
		if (!class_exists('VirtueMartCart'))
		{
			require VMPATH_SITE . DS . 'helpers' . DS . 'cart.php';
		}
		if (!class_exists('VirtueMartModelOrders'))
		{
			require VMPATH_ADMIN . DS . 'models' . DS . 'orders.php';
		}

		vmLanguage::loadJLang('com_virtuemart_orders', true);

		$virtuemartPaymentMethodId = vRequest::getInt('pm', 0);
		$orderNumber = vRequest::getString('on', '');

		if (!($method = $this->getVmPluginMethod($virtuemartPaymentMethodId)))
		{
			return null;
		}

		if (!$this->selectedThisElement($method->payment_element))
		{
			return null;
		}

		if (!($virtuemartOrderId = VirtueMartModelOrders::getOrderIdByOrderNumber($orderNumber)))
		{
			return null;
		}

		if (!($paymentTable = $this->getDataByOrderId($virtuemartOrderId)))
		{
			return null;
		}

		// Re-check the actual status from the API (the redirect may race the webhook).
		$purchaseId = isset($paymentTable->chip_purchase_id) ? $paymentTable->chip_purchase_id : '';
		$chipStatus = isset($paymentTable->chip_status) ? strtolower((string) $paymentTable->chip_status) : '';
		if ($purchaseId !== '')
		{
			$api = $this->getApi($method);
			$payment = $api->getPurchase($purchaseId);
			if (is_array($payment) && !empty($payment['id']))
			{
				$this->processPaymentStatus($payment, $method);
				$chipStatus = isset($payment['status']) ? strtolower((string) $payment['status']) : $chipStatus;
			}
		}

		$modelOrder = VmModel::getModel('orders');
		$order = $modelOrder->getOrder($virtuemartOrderId);

		$paymentName = $this->renderPluginName($method);
		$html = $this->_getPaymentResponseHtml($paymentTable, $paymentName);

		// Set the headline message based on the actual payment outcome.
		if ($paymentResponse !== null)
		{
			if ($chipStatus === 'paid')
			{
				$paymentResponse = vmText::_('COM_VIRTUEMART_CART_THANKYOU');
			}
			else
			{
				$paymentResponse = vmText::_('VMPAYMENT_CHIP_PAYMENT_FAILED_RESPONSE');
			}
		}

		$link = JRoute::_('index.php?option=com_virtuemart&view=orders&layout=details&order_number=' . $orderNumber . '&order_pass=' . $order['details']['BT']->order_pass, false);
		$html .= '<br /><a class="vm-button-correct" href="' . $link . '">' . vmText::_('COM_VIRTUEMART_ORDER_VIEW_ORDER') . '</a>';

		$cart = VirtueMartCart::getCart();
		$cart->emptyCart();

		return true;
	}

	/**
	 * User cancel handler.
	 *
	 * @return bool|null
	 */
	public function plgVmOnUserPaymentCancel()
	{
		if (!class_exists('VirtueMartModelOrders'))
		{
			require VMPATH_ADMIN . DS . 'models' . DS . 'orders.php';
		}

		$orderNumber = vRequest::getString('on', '');
		$virtuemartPaymentMethodId = vRequest::getInt('pm', '');

		if (empty($orderNumber) || empty($virtuemartPaymentMethodId) || !$this->selectedThisByMethodId($virtuemartPaymentMethodId))
		{
			return null;
		}

		if (!($virtuemartOrderId = VirtueMartModelOrders::getOrderIdByOrderNumber($orderNumber)))
		{
			return null;
		}

		if (!($paymentTable = $this->getDataByOrderId($virtuemartOrderId)))
		{
			return null;
		}

		vmInfo(vmText::_('VMPAYMENT_CHIP_PAYMENT_CANCELLED'));
		$this->handlePaymentUserCancel($virtuemartOrderId);

		return true;
	}

	/**
	 * Refund trigger: when an order moves to a refunded status in the backend,
	 * call the CHIP refund API.
	 *
	 * @param object $order
	 * @param string $oldOrderStatus
	 *
	 * @return bool|null
	 */
	public function plgVmOnUpdateOrderPayment(&$order, $oldOrderStatus)
	{
		if (!($method = $this->getVmPluginMethod($order->virtuemart_paymentmethod_id)))
		{
			return null;
		}

		if (!$this->selectedThisElement($method->payment_element))
		{
			return null;
		}

		if (!($payments = $this->getDatasByOrderId($order->virtuemart_order_id)))
		{
			return null;
		}

		$payment = $payments[0];
		$purchaseId = isset($payment->chip_purchase_id) ? $payment->chip_purchase_id : '';

		if ($purchaseId === '')
		{
			return null;
		}

		$statusRefunded = isset($method->status_refunded) ? $method->status_refunded : 'R';

		// Refund when transitioning into the refunded status.
		if ($oldOrderStatus !== $statusRefunded && $order->order_status === $statusRefunded)
		{
			$amount = isset($payment->payment_order_total) ? (float) $payment->payment_order_total : 0.0;
			$amountSen = (int) round($amount * 100);

			$api = $this->getApi($method);
			$result = $api->refundPurchase($purchaseId, $amountSen);

			if (!is_array($result))
			{
				$this->logInfo('CHIP refund failed for purchase ' . $purchaseId, 'ERROR');
				vmError(vmText::_('VMPAYMENT_CHIP_REFUND_FAILED'));

				return false;
			}

			$this->logInfo('CHIP refund completed for purchase ' . $purchaseId, 'message');
			$this->updateInternalData($order->virtuemart_order_id, array('chip_status' => 'refunded'));

			return true;
		}

		return null;
	}

	/**
	 * Backend order view: display CHIP payment details.
	 *
	 * @param int $virtuemartOrderId
	 * @param int $paymentMethodId
	 *
	 * @return string|null
	 */
	public function plgVmOnShowOrderBEPayment($virtuemartOrderId, $paymentMethodId)
	{
		if (!$this->selectedThisByMethodId($paymentMethodId))
		{
			return null;
		}

		if (!($paymentTable = $this->getDataByOrderId($virtuemartOrderId)))
		{
			return null;
		}

		vmLanguage::loadJLang('com_virtuemart');

		$html = '<table class="adminlist table">' . "\n";
		$html .= $this->getHtmlHeaderBE();
		$html .= $this->getHtmlRowBE('COM_VIRTUEMART_PAYMENT_NAME', $paymentTable->payment_name);
		$html .= $this->getHtmlRowBE('VMPAYMENT_CHIP_PURCHASE_ID', isset($paymentTable->chip_purchase_id) ? $paymentTable->chip_purchase_id : '');
		$html .= $this->getHtmlRowBE('VMPAYMENT_CHIP_STATUS', isset($paymentTable->chip_status) ? $paymentTable->chip_status : '');
		$html .= $this->getHtmlRowBE('VMPAYMENT_CHIP_TOTAL_CURRENCY', $paymentTable->payment_order_total . ' ' . $paymentTable->payment_currency);
		$html .= '</table>' . "\n";

		return $html;
	}

	/**
	 * Create the plugin data table on install.
	 *
	 * @param int $jpluginId
	 *
	 * @return bool
	 */
	public function plgVmOnStoreInstallPaymentPluginTable($jpluginId)
	{
		return $this->onStoreInstallPluginTable($jpluginId);
	}

	/**
	 * Validate the selected payment method.
	 *
	 * @param VirtueMartCart $cart
	 * @param string         $msg
	 *
	 * @return bool|null
	 */
	public function plgVmOnSelectCheckPayment(VirtueMartCart $cart, &$msg)
	{
		return $this->onSelectCheck($cart);
	}

	/**
	 * Display the payment method in the frontend list.
	 *
	 * @param VirtueMartCart $cart
	 * @param int            $selected
	 * @param string         $htmlIn
	 *
	 * @return bool|null
	 */
	public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected, &$htmlIn)
	{
		return $this->displayListFE($cart, $selected, $htmlIn);
	}

	/**
	 * Calculate the payment method price.
	 *
	 * @param VirtueMartCart $cart
	 * @param array          $cartPrices
	 * @param array          $cartPricesName
	 *
	 * @return bool|null
	 */
	public function plgVmOnSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cartPrices, &$cartPricesName)
	{
		return $this->onSelectedCalculatePrice($cart, $cartPrices, $cartPricesName);
	}

	/**
	 * Automatic selection check.
	 *
	 * @param VirtueMartCart $cart
	 * @param array          $cartPrices
	 * @param int            $paymentCounter
	 *
	 * @return bool|null
	 */
	public function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cartPrices, &$paymentCounter)
	{
		return $this->onCheckAutomaticSelected($cart, $cartPrices, $paymentCounter);
	}

	/**
	 * Show the payment method on the frontend order details.
	 *
	 * @param int    $virtuemartOrderId
	 * @param int    $virtuemartPaymentMethodId
	 * @param string $paymentName
	 *
	 * @return void
	 */
	public function plgVmOnShowOrderFEPayment($virtuemartOrderId, $virtuemartPaymentMethodId, &$paymentName)
	{
		$this->onShowOrderFE($virtuemartOrderId, $virtuemartPaymentMethodId, $paymentName);
	}

	/**
	 * Declare plugin params (VM3 compatibility).
	 *
	 * @param array $data
	 *
	 * @return mixed
	 */
	public function plgVmDeclarePluginParamsPaymentVM3(&$data)
	{
		return $this->declarePluginParams('payment', $data);
	}

	/**
	 * Set plugin params on the table (VM3 compatibility).
	 *
	 * @param string $name
	 * @param int    $id
	 * @param object $table
	 *
	 * @return mixed
	 */
	public function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
	{
		return $this->setOnTablePluginParams($name, $id, $table);
	}
}
