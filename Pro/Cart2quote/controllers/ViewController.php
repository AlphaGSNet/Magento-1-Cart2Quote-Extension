<?php
require_once 'Ophirah/Qquoteadv/controllers/ViewController.php';

class Pro_Cart2quote_ViewController extends Ophirah_Qquoteadv_ViewController
{
    public function clearAction()
    {
        foreach (Mage::getSingleton('checkout/session')->getQuote()->getItemsCollection() as $item) {
            Mage::getSingleton('checkout/cart')->removeItem($item->getId())->save();
        }
    }
    
    public function confirmAction()
    {
        $notice = '';
        $_helper = Mage::helper('cataloginventory');

        $quote = $this->_initQuote();
        if ($quote) {
            $quoteId = (int)$this->getRequest()->getParam('id');
            $params = $this->getRequest()->getParams();
            Mage::dispatchEvent('ophirah_qquoteadv_viewconfirm_before', array($quoteId, $params));

            // Load Quotation Data
            $_quote = Mage::getSingleton('qquoteadv/qqadvcustomer')->load($quoteId);
            $_quote->collectTotals();

            // Check for minimum Cart Amount
            $address = $_quote->getShippingAddress();
            $address->setAddressType('shipping');
            $minAmount = $address->validateMinimumAmount();

            if (!$minAmount && Mage::getStoreConfig('qquoteadv_quote_configuration/proposal/quoteconfirmation') != "0") {
                $notice = Mage::getStoreConfig('sales/minimum_order/error_message') ?
                    Mage::getStoreConfig('sales/minimum_order/error_message') :
                    Mage::helper('checkout')->__('Subtotal must exceed minimum order amount');
                $this->getCoreSession()->addNotice($notice);
                Mage::helper('qquoteadv')->setActiveConfirmMode(false);
                $this->_redirect('qquoteadv/view/view/id/' . $quoteId);
                return $this;
            }

            if (!isset($params['requestQtyLine'])) {
                $params['requestQtyLine'] = $_quote->getAllRequestItemsForCart();
                $params['remove_item_id'] = '';

                if ($params['requestQtyLine'] === false) {
                    $message = "Couldn't auto check out because one or more products are bundle products.";
                    Mage::log('Message: ' .$message, null, 'c2q.log', true);

                    $this->getCoreSession()->addNotice($this->__("Couldn't auto check out because one or more products are bundle products"));

                    $this->_redirect('qquoteadv/view/view/id/' . $quoteId);
                    return $this;
                }
            }

            $quoteData = $this->checkUserQuote($quoteId, $this->getCustomerId());
            if ($quoteData) {
                if (count($params['requestQtyLine']) > 0) {

                    //# Delete items from shopping cart before moving quote items to it
                    Mage::helper('qquoteadv')->setActiveConfirmMode(false); // disable first to clear the cart
                    $this->_clearShoppingCart();

                    // Check for Checkout Url
                    $useAltCheckout = false;
                    $altCheckoutUrl = false;
                    if (Mage::getStoreConfig('qquoteadv_advanced_settings/checkout/checkout_alternative_url')) {
                        $altCheckoutUrl = Mage::getStoreConfig('qquoteadv_advanced_settings/checkout/checkout_alternative_url');
                        $confAltCheckout = Mage::getStoreConfig('qquoteadv_advanced_settings/checkout/checkout_alternative', $_quote->getData('store_id'));
                        $useAltCheckout = ($confAltCheckout > 0 && $_quote->getData('alt_checkout') > 0) ? true : false;
                    }

                    // Add Salesrule
                    if ($_quote->getData('salesrule')) {
                        $couponCode = $_quote->getCouponCodeById($_quote->getData('salesrule'));
                    } else {
                        $couponCode = null;
                    }

                    //# Set QUOTE comfirmation mode to avoid manipulation with qty/price
                    Mage::helper('qquoteadv')->setActiveConfirmMode(true);
                    Mage::getSingleton('core/session')->proposal_quote_id = $quoteId;
                    //# Allow Quoteshiprate shipping method
                    Mage::getSingleton('core/session')->proposal_showquoteship = true;

                    // get Cart
                    $cart = Mage::getModel('checkout/cart');

                    foreach ($params['requestQtyLine'] as $keyProductReq => $requestId) {
                        $update = array();
                        $customPrice = 0;
                        $productId = null;

                        $x = Mage::getModel('qquoteadv/qqadvproduct')->load($keyProductReq);
                        $update['attributeEncode'] = unserialize($x->getData('attribute'));

                        $result = Mage::getModel('qquoteadv/requestitem')->getCollection()->setQuote($_quote)
                            ->addFieldToFilter('quoteadv_product_id', $keyProductReq)
                            ->addFieldToFilter('request_id', $requestId)
                            ->getData();

                        $item = $result[0];
                        if ($item) {
                            $productId = $item['product_id'];
                            $product = Mage::getModel('catalog/product')->load($productId);

                            $update['attributeEncode']['qty'] = $item['request_qty'];

                            //# GET owner price
                            $customPrice = $item['owner_cur_price'];
                            $allowed2Ordermode = $product->getData('allowed_to_ordermode');

                            try {
                                //# Trying to add item into cart
                                if ($product->isSalable() or ($allowed2Ordermode == 0 && Mage::helper('qquoteadv')->isActiveConfirmMode(true))) {

                                    $maxSaleQty = Mage::getModel('cataloginventory/stock_item')->loadByProduct($product)->getMaxSaleQty() * 1;
                                    if ($maxSaleQty > 0 && ($item['request_qty'] > $maxSaleQty)) {

                                        $notice = $_helper->__('The maximum quantity allowed for purchase is %s.', $maxSaleQty);
                                        $notice .= '<br />' . $_helper->__('Some of the products cannot be ordered in requested quantity.');

                                        continue;
                                    }

                                    if (Mage::helper('qquoteadv')->checkQuantities($product, $item['request_qty'])->getHasError() || Mage::helper('qquoteadv')->isInStock($product, $item['request_qty'])->getHasError()) {
                                        $notice = $_helper->__('Item %s is out of stock and cannot be ordered.', $product->getName());
                                        $this->getCoreSession()->addNotice($notice);
                                        return $this->_redirectReferer();
                                    }
                                    //# step1: register owner price for observer
                                    if (Mage::registry('customPrice')) {
                                        Mage::unregister('customPrice');
                                    }

                                    //fallback for situations where getWebsite doesn't return a object
                                    if(is_object(Mage::app()->getWebsite(true))){
                                        $defaultStoreId = Mage::app()->getWebsite(true)->getDefaultGroup()->getDefaultStoreId();
                                    } else {
                                        $defaultStoreId = Mage::app()->getStore('default')->getStoreId();
                                        $message = 'Mage::app()->getWebsite(true) is not a object, fallback applied';
                                        Mage::log('Message: ' .$message, null, 'c2q.log');
                                    }

                                    $quoteStoreId = $_quote->getStoreId();
                                    if($defaultStoreId != $quoteStoreId){
                                        $priceContainsTax = Mage::helper('tax')->priceIncludesTax($_quote->getStore()); //Mage::getStoreConfig('tax/calculation/price_includes_tax', $_quote->getStoreId());
                                        if($priceContainsTax == "1"){
                                            //fallback for situations where getWebsite doesn't return a object
                                            if(is_object(Mage::app()->getWebsite(true))){
                                                $store = Mage::app()->getWebsite(true)->getDefaultGroup()->getDefaultStore();
                                            } else {
                                                $store = Mage::app()->getStore('default');
                                                $message = 'Mage::app()->getWebsite(true) is not a object, fallback applied';
                                                Mage::log('Message: ' .$message, null, 'c2q.log');
                                            }

                                            $taxCalculation = Mage::getModel('tax/calculation');
                                            $request = $taxCalculation->getRateOriginRequest($store);
                                            $taxClassId = $product->getTaxClassId();
                                            $percent = $taxCalculation->getRate($request->setProductClassId($taxClassId));

                                            $quoteStore = Mage::getModel('core/store')->load($_quote->getStoreId());
                                            $taxCalculation = Mage::getModel('tax/calculation');
                                            $request = $taxCalculation->getRateRequest(null, null, null, $quoteStore);
                                            $taxClassId = $product->getTaxClassId();
                                            $quotePercent = $taxCalculation->getRate($request->setProductClassId($taxClassId));

                                            if($percent != $quotePercent){
                                                $customPrice = ($customPrice / (100+$quotePercent)) * (100+$percent);
                                            }
                                        }
                                    }

                                    Mage::register('customPrice', $customPrice);

                                    //# step2: - add item to shopping cart
                                    //         - observer catch register owner price and set it for item adding for shopping cart

                                    //add product to cart
                                    $cart->addProduct($product, $update['attributeEncode'])->setProposalQuoteId($quoteId);

                                    // Apply Coupon code to Cart
                                    if ($couponCode != null && !isset($couponCodeApplied)) {
                                        $cart->getQuote()->setCouponCode($couponCode);
                                        $couponCodeApplied = true;
                                    }

                                    //Setting Address Total Amounts in Cart Shipping address
                                    foreach ($cart->getQuote()->getAllAddresses() as $address) {
                                        // These Totals needs to be set to
                                        // check the minimal Checkout amount
                                        // See: Mage_Sales_Model_Quote::validateMinimumAmount()
                                        $updateAmounts = array('subtotal', 'discount');
                                        if ($address->getAddressType() == 'shipping') {
                                            foreach ($updateAmounts as $update) {
                                                $address->setTotalAmount($update, $_quote->getAddress()->getData($update));
                                                $address->setBaseTotalAmount($update, $_quote->getAddress()->getData('base_'.$update));
                                            }
                                        }
                                    }

                                    Mage::dispatchEvent('checkout_cart_add_product_complete',
                                        array('product' => $product, 'request' => $this->getRequest(), 'response' => $this->getResponse())
                                    );

                                    if (isset($customPrice)) {
                                        Mage::unregister('customPrice');
                                    }

                                } else {
                                    //check for "AW_Catalogpermissions"
                                    $extra = '';
                                    if(Mage::helper('core')->isModuleEnabled("AW_Catalogpermissions")){
                                        $extra = ' (Note: AW_Catalogpermissions is enabled)';
                                    }
                                    $message = 'Product: '.$product->getName().' could not be added to the cart'.$extra;

                                    //add log
                                    Mage::log('Message: ' .$message, null, 'c2q.log', true);

                                    //add notice
                                    $pre = '';
                                    if($notice != ''){
                                        $pre = '<br>';
                                    }
                                    $notice .= $pre.$_helper->__('Product: "%s" could not be added to the cart', $product->getName());
                                }
                            } catch (Mage_Core_Exception $e) {
                                Mage::log('Exception: ' .$e->getMessage(), null, 'c2q_exception.log', true);
                                $this->getCoreSession()->addError($this->__('Product: "%s" could not be added to the cart', $product->getName()));
                            }
                        }
                    }

                    $cart->save();
                    //Set Cart2Quote reference ID
                    $mageQuoteId = $cart->getQuote()->getData('entity_id');
                    Mage::helper('qquoteadv')->setReferenceIdInCoreSession($mageQuoteId, $quoteId);

                    Mage::getSingleton('core/session')->setCartWasUpdated(true);

                    // Set Coupon Code message
                    if ($couponCode != null) {
                        if ($couponCode == $cart->getQuote()->getCouponCode()) {
                            $this->getCoreSession()->addSuccess(Mage::helper('checkout')->__('Coupon code "%s" was applied.', $couponCode));
                        } else {
                            $this->getCoreSession()->addError(Mage::helper('checkout')->__('Cannot apply the coupon code.').' '.$couponCode);
                        }
                    }
                    
                    //# Set Quote status: STATUS_CONFIRMED
                    $data = array(
                        'updated_at' => now(),
                        'status' => Mage::getModel('qquoteadv/status')->getStatusConfirmed()
                    );

                    //# Disallow Quoteshiprate shipping method
                    Mage::getSingleton('core/session')->proposal_showquoteship = false;

                    Mage::dispatchEvent('qquoteadv_qqadvcustomer_beforesave_final', array('quote' => Mage::getModel('qquoteadv/qqadvcustomer')->load($quoteId)));
                    Mage::getModel('qquoteadv/qqadvcustomer')->updateQuote($quoteId, $data)->save();
                    Mage::dispatchEvent('qquoteadv_qqadvcustomer_aftersave_final', array('quote' => Mage::getModel('qquoteadv/qqadvcustomer')->load($quoteId)));

                    Mage::helper('qquoteadv/logging')->sentAnonymousData('confirm', 'f', $quoteId);

                    if ($useAltCheckout === false && empty($notice)) {
                        Mage::getModel('qquoteadv/qqadvcustomer')->sendQuoteAccepted($quoteId);
                        $this->getCoreSession()->addSuccess($this->__('All items were moved to cart successfully.'));
                    } elseif ($useAltCheckout === false) {
                        $this->getCoreSession()->addNotice($notice);
                    }

                    if ($useAltCheckout === true && empty($notice)) {
                        Mage::getModel('qquoteadv/qqadvcustomer')->sendQuoteAccepted($quoteId);
                    } elseif ($useAltCheckout === true) {
                        $this->getCoreSession()->addNotice($notice);
                    }
                    
                    if ($useAltCheckout === true) {
                        Mage::helper('qquoteadv')->setActiveConfirmMode(false);
                        
                        $onepage = Mage::getSingleton('checkout/type_onepage');
                    
                        $billingAddress = $_quote->getBillingAddress();
                        $shippingAddress = $_quote->getShippingAddress();
               /*         $billingData = array(
                            'address_id' => $billingAddress->getData('address_id'),
                            'firstname' => $billingAddress->getData('firstname'),
                            'lastname' => $billingAddress->getData('lastname'),
                            'company' => $billingAddress->getData('company'),
                            'street' => array($billingAddress->getData('street'),''),
                            'city' => $billingAddress->getData('city'),
                            'region_id' => $billingAddress->getData('region_id'),
                            'region' => $billingAddress->getData('region'),
                            'postcode' => $billingAddress->getData('postcode'),
                            'country_id' => $billingAddress->getData('country_id'),
                            'telephone' => $billingAddress->getData('telephone'),
                            'fax' => $billingAddress->getData('fax'),
                        );
                        $shippingData = array(
                            'address_id' => $shippingAddress->getData('address_id'),
                            'firstname' => $shippingAddress->getData('firstname'),
                            'lastname' => $shippingAddress->getData('lastname'),
                            'company' => $shippingAddress->getData('company'),
                            'street' => array($shippingAddress->getData('street'),''),
                            'city' => $shippingAddress->getData('city'),
                            'region_id' => $shippingAddress->getData('region_id'),
                            'region' => $shippingAddress->getData('region'),
                            'postcode' => $shippingAddress->getData('postcode'),
                            'country_id' => $shippingAddress->getData('country_id'),
                            'telephone' => $shippingAddress->getData('telephone'),
                            'fax' => $shippingAddress->getData('fax'),
                        );                        */
                        $onepage->saveBilling($billingData, $billingAddress->getCustomerAddressId());
                        $onepage->saveShipping($shippingData, $shippingAddress->getCustomerAddressId());
                        
                        $result = $onepage->saveShippingMethod($_quote->getShippingMethod());
                        if (!$result) {
                            $onepage->getQuote()->setTotalsCollectedFlag(false)->collectTotals();
                        }
                        $onepage->getQuote()->collectTotals()->save();
                        
                        $paymentData = array(
                            'method' => 'purchaseorder',
                            'po_number' => 10002
                        );                        
                        $onepage->savePayment($paymentData);
                        
                        $onepage->saveOrder();
                        $onepage->getQuote()->save();
                        
                        $data = array(
                            'updated_at' => now(),
                            'status' => Mage::getModel('qquoteadv/status')->getStatusOrdered()
                        );
                        
                        Mage::getModel('qquoteadv/qqadvcustomer')->updateQuote($quoteId, $data)->save();
                    }
                }

                // Redirect to checkout
                $url = Mage::getStoreConfig('qquoteadv_advanced_settings/checkout/checkout_url');
                if (isset($altCheckoutUrl) && $useAltCheckout === true) {
                    $this->outqqconfirmmodeAction(false);
                    $this->_redirect($altCheckoutUrl);
                } elseif ($url) {
                    $this->_redirect($url);
                } else {
                    $this->_redirect('checkout/onepage/');
                }

            } else {
                $this->getCoreSession()->addNotice(Mage::helper('adminhtml')->__('Access denied').'!');
                $this->_redirect('customer/account/');
                return null;
            }
        } else {
            $this->_forward('noRoute');
        }

        if(isset($quoteId)) {
            Mage::dispatchEvent('ophirah_qquoteadv_viewconfirm_after', array($quoteId));
        }
        return null;
    }
    
    private function checkUserQuote($quoteId, $userId)
    {
        $quote = Mage::getModel('qquoteadv/qqadvcustomer')->getCollection()
            ->addFieldToFilter('quote_id', $quoteId)
            ->addFieldToFilter('customer_id', $userId);

        return (count($quote) > 0) ? $quote : false;
    }
}
