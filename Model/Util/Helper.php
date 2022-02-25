<?php
namespace Latitude\Checkout\Model\Util;

use \Latitude\Checkout\Model\Util\Constants as LatitudeConstants;

/**
 * Class Helper
 * @package Latitude\Checkout\Model\Util
 */
class Helper
{
    const ACTIVE = 'active';
    const MERCHANT_ID = 'merchant_id';
    const MERCHANT_SECRET = 'merchant_secret';
    const STORE_MERCHANT_ID = 'store_merchant_id';
    const TEST_MODE = 'test_mode';
    const DEBUG_MODE = 'debug_mode';
    const VERSION = "version";
    const ADVANCED_CONFIG = "advanced_config";

    const API_URL_TEST = 'https://api.test.latitudefinancial.com/v1/applybuy-checkout-service';
    const API_URL_PROD = 'https://api.latitudefinancial.com/v1/applybuy-checkout-service';

    const CONTENT_HOST_TEST = 'https://develop.checkout.test.merchant-services-np.lfscnp.com';
    const CONTENT_HOST_PROD = 'https://checkout.latitudefinancial.com';

    protected $scopeConfig;
    protected $state;
    protected $request;
    protected $storeManager;

    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\App\State $state,
        \Magento\Framework\App\Request\Http $request,
        \Magento\Store\Model\StoreManagerInterface $storeManager
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->state = $state;
        $this->request = $request;
        $this->storeManager = $storeManager;
    }
    
    public function getWebsiteId()
    {
        if ($this->state->getAreaCode() == \Magento\Framework\App\Area::AREA_ADMINHTML) {
            $websiteId = (int) $this->request->getParam('website', 0);
            return $websiteId;
        }
        
        $websiteId = $this->storeManager->getStore()->getWebsiteId();
        return $websiteId;
    }

    public function getConfig()
    {
        $config[self::ACTIVE] = boolval($this->_readConfig(self::ACTIVE));
        $config[self::MERCHANT_ID] = $this->_readConfig(self::MERCHANT_ID);
        $config[self::STORE_MERCHANT_ID] = $this->_readConfig(self::STORE_MERCHANT_ID);
        $config[self::TEST_MODE] = boolval($this->_readConfig(self::TEST_MODE));
        $config[self::DEBUG_MODE] = boolval($this->_readConfig(self::DEBUG_MODE));

        return $config;
    }

    public function getMerchantId()
    {
        return $this->_readConfig(self::MERCHANT_ID);
    }

    public function getMerchantSecret()
    {
        return $this->_readConfig(self::MERCHANT_SECRET);
    }

    public function getStoreMerchantId()
    {
        return $this->_readConfig(self::STORE_MERCHANT_ID);
    }

    public function isTestMode()
    {
        return boolval($this->_readConfig(self::TEST_MODE));
    }

    public function isDebugMode()
    {
        return $this->isTestMode() || boolval($this->_readConfig(self::DEBUG_MODE));
    }

    public function getApiUrl()
    {
        return $this->isTestMode() ? self::API_URL_TEST : self::API_URL_PROD;
    }

    public function getVersion()
    {
        return $this->_readConfig(self::VERSION, true);
    }

    public function getAdvancedConfig()
    {
        return $this->_readConfig(self::ADVANCED_CONFIG, true);
    }

    public function getHMAC($payload)
    {
        $message = "";

        if (!is_array($payload)) {
            return "";
        }

        $secret = $this->_readConfig(self::MERCHANT_SECRET, true);

        if (!isset($secret)) {
            return "";
        }

        ksort($payload);

        foreach ($payload as $key => $value) {
            $message .= $key . $value;
        }

        return hash_hmac("sha256", $message, $secret);
    }

    public function getPlatformVersion()
    {
        try {
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $productMetadata = $objectManager->get('\Magento\Framework\App\ProductMetadataInterface');
            return $version = $productMetadata->getVersion();
        } catch (\Exception $e) {
            return "";
        }
    }

    public function isNZMerchant()
    {
        return $this->storeManager->getStore()->getBaseCurrencyCode() == "NZD";
    }

    public function getBaseCurrency()
    {
        return $this->storeManager->getStore()->getBaseCurrencyCode();
    }

    public function getStoreBaseUrl()
    {
        return $this->storeManager->getStore()->getBaseUrl();
    }

    public function getScriptURL() {
        $host = $this->isTestMode() ? self::CONTENT_HOST_TEST : self::CONTENT_HOST_PROD;
        return $host. "/assets/content.js?platform=". LatitudeConstants::PLATFORM_TYPE ."&merchantId=". $this->getMerchantId();
    }

    private function _readConfig($path, $returnRaw = false)
    {
        $websiteId = $this->getWebsiteId();
        $rootNode = 'payment/' . LatitudeConstants::METHOD_CODE;

        if (!empty($websiteId) && $websiteId) {
            // Fallback to the store scope if nothing is found at the website level.
            $val = $this->scopeConfig->getValue($rootNode . '/' . $path, \Magento\Store\Model\ScopeInterface::SCOPE_WEBSITES, $websiteId)
                ?? $this->scopeConfig->getValue($rootNode . '/' . $path, \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $this->storeManager->getStore()->getStoreCode());
        } else {
            $val = $this->scopeConfig->getValue($rootNode . '/' . $path, 'default');
        }

        return $returnRaw ? $val : $this->_clean($val);
    }
    
    private function _clean($string)
    {
        $result = preg_replace("/[^a-zA-Z0-9_\-]+/", "", $string);
        return $result;
    }
}
