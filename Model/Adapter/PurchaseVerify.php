<?php
namespace Latitude\Checkout\Model\Adapter;

use \Magento\Framework\Exception\LocalizedException as LocalizedException;

use \Latitude\Checkout\Logger\Logger as LatitudeLogger;
use \Latitude\Checkout\Model\Util\Constants as LatitudeConstants;
use \Latitude\Checkout\Model\Util\Helper as LatitudeHelper;
use \Latitude\Checkout\Model\Util\Convert as LatitudeConvert;
use \Latitude\Checkout\Model\Adapter\CheckoutService as LatitudeCheckoutService;

class PurchaseVerify {

    protected $cartRepository;
    protected $quoteManagement;
    protected $jsonHelper;
    protected $quoteValidator;
    protected $emailSender;
    protected $checkoutSession;
    protected $eventManager;

    protected $logger;
    protected $latitudeHelper;
    protected $latitudeConvert;
    protected $latitudeCheckoutService;

    const ERROR = "error";
    const MESSAGE = "message";
    const BODY = "body";
    const ORDER = "order";

    const RESULT = "result";

    const MERCHANT_REFERENCE = "merchantReference";
    const GATEWAY_REFERENCE = "gatewayReference";
    const TRANSACTION_REFERENCE = "transactionReference";
    const PROMOTTION_REFERENCE = "promotionReference";
    const TRANSACTION_TYPE = "transactionType";
    const AMOUNT = "amount";

    public function __construct(
        \Magento\Quote\Api\CartRepositoryInterface $cartRepository,
        \Magento\Quote\Api\CartManagementInterface $quoteManagement,
        \Magento\Framework\Json\Helper\Data $jsonHelper,
        \Magento\Quote\Model\QuoteValidator $quoteValidator,
        \Magento\Sales\Model\Order\Email\Sender\OrderSender $emailSender,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        LatitudeLogger $logger,
        LatitudeHelper $latitudeHelper,
        LatitudeConvert $latitudeConvert,
        LatitudeCheckoutService $latitudeCheckoutService
    ) {
        $this->cartRepository = $cartRepository;
        $this->quoteManagement = $quoteManagement;
        $this->jsonHelper = $jsonHelper;
        $this->quoteValidator = $quoteValidator;
        $this->emailSender = $emailSender;
        $this->checkoutSession = $checkoutSession;
        $this->eventManager = $eventManager;

        $this->logger = $logger;
        $this->latitudeHelper = $latitudeHelper;
        $this->latitudeConvert = $latitudeConvert;
        $this->latitudeCheckoutService = $latitudeCheckoutService;
    }

    public function verifyAndCreateOrder($payload)
    {
        $this->logger->debug(__METHOD__. " Begin");

        try {
            $merchantReference = $payload[self::MERCHANT_REFERENCE];

            $quote = $this->_getQuoteById($merchantReference);
            $paymentMethod = $quote->getPayment()->getMethod();

            if ($paymentMethod != LatitudeConstants::METHOD_CODE) {
                return $this->_handleError("Validation failed, Invalid payment method ". $paymentMethod);
            }

            if (!boolval($quote->getIsActive())) {
                return $this->_handleError("Could not process inactive quote");
            }

            $verifyResponse = $this->latitudeCheckoutService->post(LatitudeCheckoutService::ENDPOINT_PURCHASE_VERIFY, $payload);

            if ($verifyResponse[self::ERROR]) {
                return $this->_handleError($verifyResponse[self::MESSAGE]);
            }

            $validateResult = $this->_validateVerifyResponse($verifyResponse[self::BODY]);
            if ($validateResult[self::ERROR]) {
                return $this->_handleError($verifyResponse[self::MESSAGE]);
            }

            if ($verifyResponse[self::BODY][self::RESULT] == LatitudeConstants::TRANSACTION_RESULT_FAILED) {
                $this->logger->info(__METHOD__. " Quote updated with result: ".$verifyResponse[self::BODY][self::RESULT]);
                $this->eventManager->dispatch(LatitudeConstants::EVENT_FAILED, $payload);

                return $this->_handleError("Payment failed. ". $verifyResponse[self::BODY][self::MESSAGE]);
            }

            $order = $this->_placeOrder($merchantReference, $verifyResponse[self::BODY]);

            $this->eventManager->dispatch(LatitudeConstants::EVENT_COMPLETED, $payload);

            return $this->_handleSuccess($verifyResponse[self::BODY], $order);
        } catch (LocalizedException $le) {
            return $this->_handleError($le->getRawMessage());
        } catch (\Exception $e) {
            return $this->_handleError($e->getMessage());
        }
    }

    public function addError($quoteId, $message)
    {
        $this->_getQuoteById($quoteId)->addErrorInfo('error', 'Latitude_Checkout', 1, $message, null)->save();
    }

    private function _placeOrder($merchantReference, $verifyResponse)
    {
        $quote = $this->_getQuoteById($verifyResponse[self::MERCHANT_REFERENCE]);

        $amount = $verifyResponse[self::AMOUNT];
        $gatewayReference = $verifyResponse[self::GATEWAY_REFERENCE];
        $transactionReference = $verifyResponse[self::TRANSACTION_REFERENCE];
        $promotionReference = $verifyResponse[self::PROMOTTION_REFERENCE];

        // verify amount
        if (round((float)$quote->getGrandTotal(), 2) != round((float)$amount, 2)) {
            throw new LocalizedException(__("Amount mismatch, quote total ". $quote->getGrandTotal(). " payment total: ". $amount));
        }

        // Set payment details
        $payment = $quote->getPayment();

        $payment->importData(['method' => LatitudeConstants::METHOD_CODE]);
        $payment->setMethod(LatitudeConstants::METHOD_CODE);

        $payment->setAdditionalInformation(LatitudeConstants::GATEWAY_REFERENCE, $gatewayReference);
        $payment->setAdditionalInformation(LatitudeConstants::TRANSACTION_REFERENCE, $transactionReference);
        $payment->setAdditionalInformation(LatitudeConstants::PROMOTION_REFERENCE, $promotionReference);

        $payment->save();

        $this->logger->info('Quote data: ', $quote->getData());
        $this->logger->info('Shipping data: ', $quote->getShippingAddress()->getData());
        $this->logger->info('Billing data: ', $quote->getBillingAddress()->getData());

        $this->_handleCustomerEmail($quote);
        $this->_standardizeAddress($quote);
        $this->_ignoreAddressValidation($quote);
        
        $quote->save();

        //$this->quoteValidator->validateBeforeSubmit($quote);
        $order = $this->quoteManagement->submit($quote);

        if (!$order) {
            throw new LocalizedException(__("Could not create order for quote ". $quoteId));
        }

        $purchaseTransactionId = $gatewayReference. "-". $transactionReference. "-". $verifyResponse[self::TRANSACTION_TYPE];

        $orderPayment = $order->getPayment();
        $orderPayment->setTransactionAdditionalInfo(\Magento\Sales\Model\Order\Payment\Transaction::RAW_DETAILS, $verifyResponse);
        
        $orderPayment->setTransactionId($purchaseTransactionId)->setParentTransactionId($payment->getTransactionId());

        // TODO: skip this step for authorisation trans type
        $orderPayment->setIsTransactionClosed(false)->registerCaptureNotification($order->getBaseGrandTotal());

        $orderPayment->save();
        $order->save();

        switch ($order->getState()) {
            // handle auth/capture exceptions caused by paypal or bank capture
            case \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT:
                break;
            // ok
            case \Magento\Sales\Model\Order::STATE_PROCESSING:
            case \Magento\Sales\Model\Order::STATE_COMPLETE:
            case \Magento\Sales\Model\Order::STATE_PAYMENT_REVIEW:
                $this->emailSender->send($order);
                $this->checkoutSession->start();
                break;
            default:
                break;
        }

        return $order;
    }

    private function _getQuoteById($quoteId)
    {
        $quote = $this->cartRepository->get($quoteId);

        if (!$quote->getGrandTotal()) {
            throw new LocalizedException(
                __("Cannot process quote with zero balance.")
            );
        }

        if (!$quote->getId()) {
            throw new LocalizedException(
                __("Error loading quote {$quoteId}.")
            );
        }

        if (!boolval($quote->getIsActive())) {
            throw new LocalizedException(
                __("Could not process inactive quote {$quoteId}.")
            );
        }

        return $quote;
    }

    private function _handleCustomerEmail($quote) {
        if (!empty($quote->getCustomerEmail())) {
            return;
        }

        if (!empty($quote->getBillingAddress()->getEmail())) {
            $quote->setCustomerEmail((string)$quote->getBillingAddress()->getEmail());
            return;
        }

        if (!empty($quote->getShippingAddress()->getEmail())) {
            $quote->setCustomerEmail((string)$quote->getShippingAddress()->getEmail());
            return;
        }

        //if at this point quote's email still null, i.e. Shipping and Billing both have no email populated
        if (empty($quote->getCustomerEmail()) && empty($quote->getBillingAddress()->getEmail()) && empty($quote->getShippingAddress()->getEmail())) {
            $quote->getShippingAddress()->setShouldIgnoreValidation(true);
        }
    }

    private function _standardizeAddress($quote){
        $billing = $quote->getBillingAddress();
        $shipping = $quote->isVirtual() ? null : $quote->getShippingAddress();
        $customerBillingData = $billing->exportCustomerAddress();

        if ($shipping) {
            if (!$shipping->getSameAsBilling()) {
                $customerShippingData = $shipping->exportCustomerAddress();
                $customerShippingData->setIsDefaultShipping(true);
                $shipping->setCustomerAddressData($customerShippingData);
                // Add shipping address to quote since customer Data Object does not hold address information
                $quote->addCustomerAddress($customerShippingData);
            } else {
                $shipping->setCustomerAddressData($customerBillingData);
                $customerBillingData->setIsDefaultShipping(true);
            }
        } else {
            $customerBillingData->setIsDefaultShipping(true);
        }
    }
    
    private function _ignoreAddressValidation($quote) {
        $quote->getBillingAddress()->setShouldIgnoreValidation(true);

        if (!$quote->getIsVirtual()) {
            $quote->getShippingAddress()->setShouldIgnoreValidation(true);
        }
    }

    private function _validateVerifyResponse($response)
    {
        if (!in_array($response[self::RESULT], array(
                LatitudeConstants::TRANSACTION_RESULT_COMPLETED,
                LatitudeConstants::TRANSACTION_RESULT_FAILED
            ))) {
            return [
                "error" => true,
                "message" => "Unsupported result"
            ];
        }

        if (!in_array($response[self::TRANSACTION_TYPE], array(
                LatitudeConstants::TRANSACTION_TYPE_SALE
            ))) {
            return [
                "error" => true,
                "message" => "Unsupported transaction type"
            ];
        }

        return [
            "error" => false,
        ];
    }

    private function _handleSuccess($body, $order)
    {
        return [
            self::ERROR => false,
            self::BODY => $body,
            self::ORDER => $order
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