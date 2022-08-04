# latitude-financial/checkout

Payment Gateway Integration for Latitude Interest Free products

## Install module

### Open Command line interface and navigate to Magento directory

```sh
    cd /var/www/html/magento
```

### Configure Latitude checkout module

```sh
    # add -vvv for verbose mode
    composer require latitude-financial/checkout  # new installation
    composer update latitude-financial/checkout  # update existing installation
```

### Upgrade your store instance 

```sh
    php bin/magento setup:upgrade
```

### Compile and deploy static content

```sh
    php bin/magento setup:di:compile
    php bin/magento setup:static-content:deploy
```

### Flush the cache from Magento Admin

```
    System > Cache Management > Flush Cache Storage
```

## Enable payment method

> Before proceeding with next steps, make sure that you have credentials to use Latitude Checkout.


1. Navigate to Magento Admin > Stores > Configuration > Sales > Payment Methods> Latitude

2. Enter the Merchant ID and Secret Key

3. Enable payment method by using "Is Enabled ?"

4. Enable / Disable test mode by using "Is Test mode"

5. Click on "Save" to save the configuration
