<?php
class WTC_GUR_Model_Checkout_Type_Onepage extends Mage_Checkout_Model_Type_Onepage
{
	public function saveBilling($data, $customerAddressId)
    {
        if (empty($data)) {
            return array('error' => -1, 'message' => Mage::helper('checkout')->__('Invalid data.'));
        }
		$loginSession = Mage::getSingleton('customer/session')->isloggedIn();
		$isEnabled = Mage::getStoreConfig('gur/guestuser/enable_gur');
		
		if(!$loginSession && $isEnabled)
		{
			$this->registerGuestUser();
		}
		
        $address = $this->getQuote()->getBillingAddress();
        /* @var $addressForm Mage_Customer_Model_Form */
        $addressForm = Mage::getModel('customer/form');
        $addressForm->setFormCode('customer_address_edit')
            ->setEntityType('customer_address')
            ->setIsAjaxRequest(Mage::app()->getRequest()->isAjax());

        if (!empty($customerAddressId)) {
            $customerAddress = Mage::getModel('customer/address')->load($customerAddressId);
            if ($customerAddress->getId()) {
                if ($customerAddress->getCustomerId() != $this->getQuote()->getCustomerId()) {
                    return array('error' => 1,
                        'message' => Mage::helper('checkout')->__('Customer Address is not valid.')
                    );
                }

                $address->importCustomerAddress($customerAddress)->setSaveInAddressBook(0);
                $addressForm->setEntity($address);
                $addressErrors  = $addressForm->validateData($address->getData());
                if ($addressErrors !== true) {
                    return array('error' => 1, 'message' => $addressErrors);
                }
            }
        } else {
			
			
            $addressForm->setEntity($address);
            // emulate request object
            $addressData    = $addressForm->extractData($addressForm->prepareRequest($data));
            $addressErrors  = $addressForm->validateData($addressData);
            if ($addressErrors !== true) {
                return array('error' => 1, 'message' => array_values($addressErrors));
            }
            $addressForm->compactData($addressData);
            //unset billing address attributes which were not shown in form
            foreach ($addressForm->getAttributes() as $attribute) {
                if (!isset($data[$attribute->getAttributeCode()])) {
                    $address->setData($attribute->getAttributeCode(), NULL);
                }
            }
            $address->setCustomerAddressId(null);
            // Additional form data, not fetched by extractData (as it fetches only attributes)
            $address->setSaveInAddressBook(empty($data['save_in_address_book']) ? 0 : 1);
        }

        // validate billing address
        if (($validateRes = $address->validate()) !== true) {
            return array('error' => 1, 'message' => $validateRes);
        }

        $address->implodeStreetAddress();

        if (true !== ($result = $this->_validateCustomerData($data))) {
            return $result;
        }

        if (!$this->getQuote()->getCustomerId() && self::METHOD_REGISTER == $this->getQuote()->getCheckoutMethod()) {
            if ($this->_customerEmailExists($address->getEmail(), Mage::app()->getWebsite()->getId())) {
                return array('error' => 1, 'message' => $this->_customerEmailExistsMessage);
            }
        }

        if (!$this->getQuote()->isVirtual()) {
            /**
             * Billing address using otions
             */
            $usingCase = isset($data['use_for_shipping']) ? (int)$data['use_for_shipping'] : 0;

            switch ($usingCase) {
                case 0:
                    $shipping = $this->getQuote()->getShippingAddress();
                    $shipping->setSameAsBilling(0);
                    break;
                case 1:
                    $billing = clone $address;
                    $billing->unsAddressId()->unsAddressType();
                    $shipping = $this->getQuote()->getShippingAddress();
                    $shippingMethod = $shipping->getShippingMethod();

                    // Billing address properties that must be always copied to shipping address
                    $requiredBillingAttributes = array('customer_address_id');

                    // don't reset original shipping data, if it was not changed by customer
                    foreach ($shipping->getData() as $shippingKey => $shippingValue) {
                        if (!is_null($shippingValue) && !is_null($billing->getData($shippingKey))
                            && !isset($data[$shippingKey]) && !in_array($shippingKey, $requiredBillingAttributes)
                        ) {
                            $billing->unsetData($shippingKey);
                        }
                    }
                    $shipping->addData($billing->getData())
                        ->setSameAsBilling(1)
                        ->setSaveInAddressBook(0)
                        ->setShippingMethod($shippingMethod)
                        ->setCollectShippingRates(true);
                    $this->getCheckout()->setStepData('shipping', 'complete', true);
                    break;
            }
        }

        $this->getQuote()->collectTotals();
        $this->getQuote()->save();

        if (!$this->getQuote()->isVirtual() && $this->getCheckout()->getStepData('shipping', 'complete') == true) {
            //Recollect Shipping rates for shipping methods
            $this->getQuote()->getShippingAddress()->setCollectShippingRates(true);
        }

        $this->getCheckout()
            ->setStepData('billing', 'allow', true)
            ->setStepData('billing', 'complete', true)
            ->setStepData('shipping', 'allow', true);

        return array();
    }
	
	public function registerGuestUser()
	{
		//check email id exists or not
		$email = $_POST['billing']['email'];
		$firstName = $_POST['billing']['firstname'];
		$lastName = $_POST['billing']['lastname'];
		$customer = Mage::getModel('customer/customer')
                    ->setWebsiteId(Mage::app()->getWebsite()->getId())
                    ->loadByEmail($email);

        if($customer->getId())
		{
			Mage::getSingleton('customer/session')->setCustomerAsLoggedIn($customer);
        }
		
		
		//code ended
		
		/* create guest user customer group code start*/
		$customer_group=Mage::getModel('customer/group');
		$collection = $customer_group->getCollection();
		$allGroups  = $customer_group->getCollection()->toOptionHash();
		$code = Mage::getStoreConfig('gur/guestuser/customer_group');
		if(!$code)
		{
			$code = 'Guest User';
		}
		if(!in_array($code,$allGroups))
		{
			$customer_group->setCode($code);
			$customer_group->setTaxClassId(3);
			$customer_group->save();
		}
		foreach($collection as $group)
		{
			$groupName = $group->getCustomerGroupCode();
			if($groupName == $code)
			{
				$id = $group->getCustomerGroupId();
			}
		}
		/* create guest user customer group code end*/
		
		// update terms and conditions for user
		$email_id = '"'.$email.'"';
		$resource     = Mage::getSingleton('core/resource');
		$connection   = $resource->getConnection('core_write');
		$table        = $resource->getTableName('termsprivacy');
		$query        = "INSERT INTO {$table} (`email`,`agree`) VALUES ($email_id, 1);";
		$connection->multiQuery($query);
		
		
		$customer->setEmail($email)
                 ->setFirstname($firstName)
                 ->setLastname($lastName)
                 ->setPassword($customer->generatePassword(10))
				 ->setGroupId($id)
                 ->save();

        $customer->setConfirmation(null);
        $customer->save();
        $customer->sendNewAccountEmail();
		Mage::getSingleton('customer/session')->setCustomerAsLoggedIn($customer);  
			return;
			
	}
}
		