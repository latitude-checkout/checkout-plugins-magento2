<?php
namespace Latitude\Checkout\Model\Adapter;

use \Magento\Framework\Exception\LocalizedException as LocalizedException;

use \Latitude\Checkout\Logger\Logger as LatitudeLogger;
use \Latitude\Checkout\Model\Util\Constants as LatitudeConstants;
use \Latitude\Checkout\Model\Util\Helper as LatitudeHelper;
use \Latitude\Checkout\Model\Util\Convert as LatitudeConvert;
use \Latitude\Checkout\Model\Adapter\CheckoutService as LatitudeCheckoutService;

class Purchase
{
    protected $storeManagerInterface;
    protected $productRepositoryInterface;
    protected $regionHelper;
    
    protected $logger;
    protected $latitudeHelper;
    protected $latitudeConvert;
    protected $latitudeCheckoutService;

    const ERROR = "error";
    const MESSAGE = "message";
    const BODY = "body";
    const RESULT = "result";
    const REDIRECT_URL = "redirectUrl";

    public function __construct(
        \Magento\Store\Model\StoreManagerInterface $storeManagerInterface,
        \Magento\Catalog\Api\ProductRepositoryInterface $productRepositoryInterface,
        \Magento\Directory\Model\Region $regionHelper,
        LatitudeLogger $logger,
        LatitudeHelper $latitudeHelper,
        LatitudeConvert $latitudeConvert,
        LatitudeCheckoutService $latitudeCheckoutService
    ) {
        $this->storeManagerInterface = $storeManagerInterface;
        $this->productRepositoryInterface = $productRepositoryInterface;
        $this->regionHelper = $regionHelper;

        $this->logger = $logger;
        $this->latitudeHelper = $latitudeHelper;
        $this->latitudeConvert = $latitudeConvert;
        $this->latitudeCheckoutService = $latitudeCheckoutService;
    }

    public function process($quote, $quoteId)
    {
        $this->logger->debug(__METHOD__. " Begin");

        try {
            $purchaseRequest = $this->_prepareRequest($quote, $quoteId);
            $this->_validateAddress($purchaseRequest);

            $purchaseResponse = $this->latitudeCheckoutService->post(LatitudeCheckoutService::ENDPOINT_PURCHASE, $purchaseRequest);

            if ($purchaseResponse[self::ERROR]) {
                return $this->_handleError($purchaseResponse[self::MESSAGE]);
            }

            if (empty($purchaseResponse[self::BODY][self::REDIRECT_URL])) {
                return $this->_handleError("redirect url is empty");
            }

            return $this->_handleSuccess($purchaseResponse[self::BODY]);
        } catch (LocalizedException $le) {
            return $this->_handleError($le->getRawMessage());
        } catch (\Exception $e) {
            return $this->_handleError($e->getMessage());
        }
    }

    private function _prepareRequest($quote, $quoteId)
    {
        $this->logger->info(__METHOD__ . " preparing purchase");

        $paymentGatewayConfig = $this->latitudeHelper->getConfig();
        $baseUrl = $this->storeManagerInterface->getStore($quote->getStore()->getId())->getBaseUrl();

        $additionalData = $quote->getData();

        $currency = (string)$additionalData['store_currency_code'];

        if (!in_array($currency, LatitudeConstants::ALLOWED_CURRENCY)) {
            throw new LocalizedException(__("Unsupported currency ". $paymentRequest["x_currency"]));
        }

        $billingAddress = $quote->getBillingAddress();
        $shippingAddress = $quote->getShippingAddress();

        $purchaseRequest = [
            "merchantId" => $paymentGatewayConfig[LatitudeHelper::MERCHANT_ID],
            "isTest" =>  $paymentGatewayConfig[LatitudeHelper::TEST_MODE],
            "merchantReference" => isset($quoteId) ? $quoteId : $quote->getIncrementId(),
            "amount" => $this->latitudeConvert->toPrice($quote->getGrandTotal()),
            "currency" => $currency,
            
            "customer" => [
               "firstName" => $quote->getCustomerFirstname() ? (string)$quote->getCustomerFirstname() : $billingAddress->getFirstname(),
               "lastName" => $quote->getCustomerLastname() ? (string)$quote->getCustomerLastname() : $billingAddress->getLastname(),
               "phone" => (string)$billingAddress->getTelephone(),
               "email" => $this->_getEmail($quote),
            ],

            "shippingAddress" => $this->_getAddress($shippingAddress),

            "billingAddress" => $this->_getAddress($billingAddress),

            "orderLines" => $this->_getOrderLines($quote),

            "merchantUrls" => [
                "cancel" => $baseUrl . LatitudeConstants::CANCEL_ROUTE,
                "complete" => $baseUrl . LatitudeConstants::COMPLETE_ROUTE . "?reference={$quoteId}",
            ],

            "totalShippingAmount" => $this->_getShippingAmount($shippingAddress),
            "totalTaxAmount" => $this->_getTaxAmount($shippingAddress, $additionalData),
            "totalDiscountAmount" => $this->_getDiscountAmount($quote, $additionalData),

            "platformType" => LatitudeConstants::PLATFORM_TYPE,
            "platformVersion" => $this->latitudeHelper->getPlatformVersion(),
            "pluginVersion" => $this->latitudeHelper->getVersion(),
        ];

        // Adding the `storeMid` parameter if the current store configuration has a value for it.
        $storeMid = $paymentGatewayConfig[LatitudeHelper::STORE_MERCHANT_ID] ?? null;
        if ($storeMid) {
            $purchaseRequest['storeMid'] = $paymentGatewayConfig[LatitudeHelper::STORE_MERCHANT_ID];
        }

        return $purchaseRequest;
    }

    private function _getEmail($quote)
    {
        if (!empty($quote->getCustomerEmail())) {
            return (string)$quote->getCustomerEmail();
        }

        if (!empty($quote->getShippingAddress()->getEmail())) {
            return (string)$quote->getShippingAddress()->getEmail();
        }

        if (!empty($quote->getBillingAddress()->getEmail())) {
            return (string)$quote->getBillingAddress()->getEmail();
        }

        return "";
    }

    private function _getAddress($addr)
    {
        if (empty($addr) || empty($addr->getStreetLine(1))) {
            return array();
        }

        return [
            "name" => (string)$addr->getFirstname() . ' ' . $addr->getLastname(),
            "line1" => (string)$addr->getStreetLine(1),
            "line2" => (string)$addr->getStreetLine(2),
            "city" => (string)$addr->getCity(),
            "postCode" =>  (string)$addr->getPostcode(),
            "state" => (string)$this->_getRegion($addr),
            "countryCode" => (string)$addr->getCountryId(),
            "phone" => (string)$addr->getTelephone(),
        ];
    }

    private function _getOrderLines($quote)
    {
        $orderLines = array();

        if (is_array($quote->getAllVisibleItems())) {
            foreach ($quote->getAllVisibleItems() as $key => $item) {
                if (!$item->getParentItem()) {
                    $product = $this->productRepositoryInterface->getById($item->getProductId());

                    $unitPrice = $this->latitudeConvert->toPrice($item->getPriceInclTax());
                    $totalPrice = $this->latitudeConvert->toPrice((float)$item->getQty() * (float)$item->getPrice());
                    $totalPriceInclDiscount = $this->latitudeConvert->toPrice((float)$item->getQty() * (float)$item->getPriceInclTax());
                    $totalTax = $this->latitudeConvert->toPrice(($totalPriceInclDiscount - $totalPrice));
                    
                    $orderLineItem = [];

                    $orderLineItem["name"] = (string)$item->getName();
                    $orderLineItem["productUrl"] = (string)$item->getProductUrl();
                    $orderLineItem["sku"] = (string)$item->getSku();
                    $orderLineItem["quantity"] = (float)$item->getQty();
                    $orderLineItem["unitPrice"] = $unitPrice;
                    $orderLineItem["amount"] = $totalPriceInclDiscount;
                    $orderLineItem["tax"] = $totalTax;
                    $orderLineItem["requiresShipping"] = !$quote->isVirtual();
                    $orderLineItem["isGiftCard"] = $quote->isVirtual();

                    array_push($orderLines, $orderLineItem);
                }
            }
        }

        return $orderLines;
    }

    private function _getShippingAmount($shippingAddress)
    {
        if ($shippingAddress->getShippingAmount()) {
            return $this->latitudeConvert->toPrice($shippingAddress->getShippingAmount());
        }

        return 0;
    }

    private function _getTaxAmount($shippingAddress, $additionalData)
    {
        if (isset($additionalData['tax_amount'])) {
            return $this->latitudeConvert->toPrice($additionalData['tax_amount']);
        }

        if ($shippingAddress->getTaxAmount()) {
            return$this->latitudeConvert->toPrice($shippingAddress->getTaxAmount());
        }

        return 0;
    }

    private function _getDiscountAmount($quote, $additionalData)
    {
        if (isset($additionalData['discount_amount'])) {
            return $this->latitudeConvert->toPrice($additionalData['discount_amount']);
        }

        if ($quote->getBaseSubtotal() && $quote->getBaseSubtotalWithDiscount()) {
            $discount = $quote->getBaseSubtotal() - $quote->getBaseSubtotalWithDiscount();
            return $this->latitudeConvert->toPrice($discount);
        }

        return 0;
    }

    private function _getRegion($address)
    {
        if ($address->getRegionCode()) {
            return $address->getRegionCode();
        }

        $region = $this->regionHelper->load($address->getRegionId());
        return $region->getCode();
    }

    private function _validateAddress($paymentRequest)
    {
        $this->logger->info(__METHOD__ . " validating address");

        $errors = [];

        $billingAddress = $paymentRequest["billingAddress"];

        if (!isset($billingAddress)) {
            throw new LocalizedException(__("billing address cannot be empty"));
        }
       
        if (empty($billingAddress['line1'])) {
            $errors[] = 'invalid billing line 1';
        }

        if (empty($billingAddress['city'])) {
            $errors[] = 'invalid billing city';
        }
        
        if (empty($billingAddress['postCode']) || strlen(trim($billingAddress['postCode'])) < 3) {
            $errors[] = 'invalid billing postcode';
        }

        if (empty($billingAddress['countryCode'])) {
            $errors[] = 'invalid billing country code';
        }
        
        if (count($errors)) {
            throw new LocalizedException(__(implode($errors, ' ; ')));
        }

        return true;
    }

    private function _handleSuccess($body)
    {
        $this->logger->info(__METHOD__. " ". \json_encode($body));

        return [
            self::ERROR => false,
            self::BODY => $body
        ];
    }

    private function _handleError($message)
    {
        $this->logger->error(__METHOD__. " ". $message);

        return [
            self::ERROR => true,
            self::MESSAGE => $message
        ];
    }
}
