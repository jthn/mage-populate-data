<?php
require_once 'app/Mage.php';
require_once 'populate.php';
Mage::app();

set_time_limit(5);
$customer = Builder::create_customer();
Builder::create_sale($customer);
Mage::log($argv[1] . ' sales created' . PHP_EOL);

exit(0);

class Builder 
{
	public static function create_customer()
	{
		$customer = Mage::getModel('customer/customer');

		try {
			$person = self::get_user_data();

			$customer->setWebsiteId(Mage::app()->getWebsite()->getId());
			$customer->loadByEmail($person->user->email);

			if(!$customer->getId()) {
				$customer->setEmail($person->user->email);
				$customer->setFirstname(ucwords($person->user->name->first));
				$customer->setLastname(ucwords($person->user->name->last));
				$customer->setPassword(substr(md5($person->user->password), 8));
			}

			$customer->setConfirmation(null);
			$customer->save();

			//Make a "login" of new customer
			Mage::getSingleton('customer/session')->loginById($customer->getId());
		}
		catch (Exception $ex) {
			var_dump($ex->getMessage());
		}

		$region = self::get_region_from_state($person->user->location->state);

		//Build billing and shipping address for customer, for checkout
		$_custom_address = array (
			'firstname' => $customer->getFirstname(),
			'lastname' => $customer->getLastname(),
			'street' => array (
				'0' => ucwords($person->user->location->street),
			),
			'city' => ucwords($person->user->location->city),
			'region_id' => $region->region_id,
			'region' => $region->code,
			'postcode' => $person->user->location->zip,
			'country_id' => 'US',
			'telephone' => $person->user->phone,
		);

		$customAddress = Mage::getModel('customer/address');

		$customAddress->setData($_custom_address)
			->setCustomerId($customer->getId())
			->setIsDefaultBilling('1')
			->setIsDefaultShipping('1')
			->setSaveInAddressBook('1');
		try {
			Mage::log("Saving custom address");
			$customAddress->save();
		}
		catch (Exception $ex) {
			var_dump($ex->getMessage());
		}
		Mage::getSingleton('checkout/session')
			->getQuote()
			->setBillingAddress(Mage::getSingleton('sales/quote_address')->importCustomerAddress($customAddress))
			->setShippingAddress(Mage::getSingleton('sales/quote_address')->importCustomerAddress($customAddress));

		return $customer;
	}

	public static function create_sale($customer)
	{
		Mage::log("Creating sale");
		$cart = Mage::getSingleton('checkout/cart');

		try {
			foreach(self::get_product_ids() as $id) {
				$product = Mage::getModel('catalog/product')->load($id);
				Mage::log($product->getName() . ' loaded');
				$cart->addProduct($product);
			}
			Mage::log("Saving cart");
			$cart->save();
		}
		catch (Exception $ex) {
			Mage::logException($ex);
		}
		unset($product);

		$storeId = Mage::app()->getStore()->getId();

		Mage::log("Init Checkout");

		$checkout = Mage::getSingleton('checkout/type_onepage');
		$checkout->initCheckout();
		$checkout->saveCheckoutMethod('register');
		$checkout->saveShippingMethod('flatrate_flatrate');
		$checkout->savePayment(array('method'=>'checkmo'));
		try {
			$checkout->saveOrder();
		}
		catch (Exception $ex) {
			Mage::logException($ex);
		}
		/* Clear the cart */
		$cart->truncate();
		$cart->save();
		$cart->getItems()->clear()->save();
		/* Logout the customer you created */
		Mage::getSingleton('customer/session')->logout();
	}

	public static function get_product_ids()
	{
		$product_ids = array();

		$ids = Mage::getModel('catalog/product')
			->getCollection()
			->addAttributeToFilter('type_id', 'simple')
			->getAllIds();

		$range = count($ids);

		$count = rand(1,3);

		for($i = 0; $i < $count; $i++) {
			$product_ids[] = $ids[rand(0, $range - 1)];
		}

		Mage::log("Adding products (" . implode(',', $product_ids) . ") to cart");

		return $product_ids;
	}

	public static function get_user_data()
	{
		$seed = md5(rand(0,getrandmax()));

		Mage::log("Generating user for seed $seed");

		$url = 'http://api.randomuser.me/?seed=' . $seed;
		$ch = curl_init($url);

		// Configuring curl options
		$options = array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => array('Content-type: application/json'),
		);

		curl_setopt_array($ch, $options);
		
		$result = json_decode(curl_exec($ch));

		Mage::log("Request complete");

		return $result->results[0];
	}

	public static function get_region_from_state($name)
	{
		$db = Mage::getSingleton('core/resource')->getConnection('core_read');
		$table = Mage::getSingleton('core/resource')->getTablename('directory_country_region');
		$sql = "SELECT * FROM {$table} WHERE default_name = ?";

		Mage::log("Getting region detail for $name");
		return (object) $db->fetchRow($sql, $name);
	}
}
