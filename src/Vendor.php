<?php

namespace Electroneum\Vendor;

use Electroneum\Vendor\Exception\VendorException;

class Vendor
{
    /**
     * Version number of this vendor class.
     */
    const API_VERSION = '0.1.0';

    /**
     * Url to poll for payment confirmation.
     */
    const URL_POLL = 'https://poll.electroneum.com/vendor/check-payment';

    /**
     * Url for the exchange rate JSON.
     */
    const URL_SUPPLY = 'https://supply.electroneum.com/app-value-v2.json';

    /**
     * Url (sprintf) to load a QR code.
     */
    const URL_QR = 'https://chart.googleapis.com/chart?cht=qr&chs=300x300&chld=L|0&chl=%s';

    /**
     * @var array
     *
     * Currencies accepted for converting to ETN.
     */
    protected $currencies = ["AUD","BRL","BTC","CAD","CDF","CHF","CLP","CNY","CZK","DKK","EUR","GBP","HKD","HUF","IDR","ILS","INR","JPY","KRW","MXN","MYR","NOK","NZD","PHP","PKR","PLN","RUB","SEK","SGD","THB","TRY","TWD","USD","ZAR"];

    /**
     * @var string
     *
     * Your vendor API key.
     */
    private $apiKey;

    /**
     * @var string
     *
     * Your vendor API secret.
     */
    private $apiSecret;

    /**
     * @var float
     *
     * The amount to charge in ETN.
     */
    private $etn;

    /**
     * @var string
     *
     * The outlet id.
     */
    private $outlet;

    /**
     * @var string
     *
     * The payment id.
     */
    private $paymentId;

    /**
     * Get etn
     *
     * @return float
     */
    public function getEtn()
    {
        return $this->etn;
    }

    /**
     * Get outlet
     *
     * @return string
     */
    public function getOutlet()
    {
        return $this->outlet;
    }

    /**
     * Get paymentId
     *
     * @return string
     */
    public function getPaymentId()
    {
        return $this->paymentId;
    }

    /**
     * Instantiate a new Electroneum vendor client.
     *
     * @param string $apiKey
     * @param string $apiSecret
     */
    public function __construct($apiKey = null, $apiSecret = null)
    {
        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;
    }

    /*
     * Generate a cryptographic random payment id.
     *
     * @throws VendorException
     *
     * @return string
     */
    public function generatePaymentId()
    {
        try {
            $this->paymentId = bin2hex(random_bytes(5));
            return $this->paymentId;
        } catch (\Exception $e) {
            // CryptGenRandom (Windows), getrandom(2) (Linux) or /dev/urandom (others) was unavailable to generate random bytes.
            throw new VendorException($e->getMessage());
        }
    }

    /*
     * Convert a local currency amount to ETN.
     *
     * @param float $amount
     * @param string $currency
     *
     * @throws VendorException
     *
     * @return float
     */
    public function currencyToEtn($value, $currency)
    {
				

        // Check the currency is accepted.
        if (!in_array(strtoupper($currency), $this->currencies)) {
            throw new VendorException("Unknown currency");
        }

        // Get the JSON conversion data.
        if (!$json = file_get_contents(Vendor::URL_SUPPLY)) {
            throw new VendorException("Could not load currency conversion JSON");
        }

        // Check the JSON is valid.
        $arr = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new VendorException("Could not load valid currency conversion JSON");
        }


        // Get the conversion rate.
        if (!$rate = $arr['price_' . strtolower($currency)]) {
            throw new VendorException("Currency rate not found");
        }

        // Check the rate is valid or more than zero (the following division would also fail).
        if (floatval($rate) <= 0) {
            throw new VendorException("Currency conversion rate not valid");
        }

        $this->etn = number_format(floatval($value) / $rate, 2, '.', '');
		

        return $this->etn;
    }

    /**
     * Generate a QR code for a vendor transaction.
     *
     * @param float  $amount
     * @param string $outlet
     * @param string $paymentId
     *
     * @throws VendorException
     *
     * @return string
     */
    public function getQrCode($amount, $outlet, $paymentId = null)
    {
        // Generate/validate the paymentId.
        if ($paymentId === null) {
            $paymentId = $this->generatePaymentId();
        } elseif (strlen($paymentId) !== 10 || !ctype_xdigit($paymentId)) {
            throw new VendorException("Qr code payment id is not valid");
        }
        $this->paymentId = $paymentId;

        // Validate the amount.
        if (empty($amount) || floatval($amount) != $amount) {
            throw new VendorException("Qr code amount is not valid");
        } else {
            $this->etn = floatval($amount);
        }

        // Validate the outlet.
        if (empty($outlet) || !ctype_xdigit($outlet)) {
            throw new VendorException("Qr code outlet is not valid");
        } else {
            $this->outlet = $outlet;
        }

        // Return the QR code string.
        $qrCode = sprintf("etn-it-%s/%s/%.2f", $this->outlet, $this->paymentId, $this->etn);
        return $qrCode;
    }

    /**
     * Return a QR image Url for a QR code string.
     *
     * @param string $qrCode
     *
     * @return string
     */
    public function getQrUrl($qrCode)
    {
        return sprintf(Vendor::URL_QR, urlencode($qrCode));
    }

    /**
     * Return a QR image Url for given data (grouping the above functions into one).
     *
     * @param float $amount
     * @param string $currency
     * @param string $outlet
     * @param string $paymentId
     *
     * @throws VendorException
     *
     * @return string
     */
    public function getQr($amount, $currency, $outlet, $paymentId = null)
    {
        // Convert the currency.
        $etn = $this->currencyToEtn($amount, $currency);

        // Build the QR Code string.
        $qrCode = $this->getQrCode($etn, $outlet, $paymentId);

        return $this->getQrUrl($qrCode);
    }

    /**
     * Validate a webhook signature based on a payload.
     *
     * @param string $payload
     * @param string $signature
     *
     * @throws VendorException
     *
     * @return boolean
     */
    public function verifySignature($payload, $signature)
    {
        // Check we have a vendor API key.
        if (empty($this->apiKey)) {
            throw new VendorException("No vendor API key set");
        }

        // Check we have a vendor API secret.
        if (empty($this->apiSecret)) {
            throw new VendorException("No vendor API secret set");
        }

        // Check we have a valid payload.
        $payload = json_decode($payload, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload) || empty($payload)) {
            throw new VendorException("Verify signature `payload` is not valid");
        }

        // Check we have a valid signature.
        if (empty($signature) || strlen($signature) !== 64 || !ctype_xdigit($signature)) {
            throw new VendorException("Verify signature `signature` is not valid");
        }

        // Validate the signature.
        if ($payload['key'] !== $this->apiKey) {
            // This isn't the payload you are looking for.
            return false;
        } elseif ($signature !== hash_hmac('sha256', json_encode($payload), $this->apiSecret)) {
            // Invalid webhook signature.
            return false;
        } elseif (strtotime($payload['timestamp']) < strtotime('-5 minutes')) {
            // Expired webhook.
            return false;
        } else {
            // Valid webhook signature.
            return true;
        }
    }

    /**
     * Generate a signature for a payload.
     *
     * @param string $payload
     *
     * @throws VendorException
     *
     * @return array
     */
    public function generateSignature($payload)
    {
        // Check we have a vendor API secret.
        if (empty($this->apiSecret)) {
            throw new VendorException("No vendor API secret set");
        }

        // Check we have a valid payload.
        $payloadArray = json_decode($payload, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($payloadArray) || empty($payloadArray)) {
            throw new VendorException("Generate signature `payload` is not valid");
        }
		

        // Validate the signature.
        return hash_hmac('sha256', $payload, $this->apiSecret);
    }

    /**
     * Poll the API to check a vendor payment. The signature will be generated if not supplied.
     *
     * @param string $payload
     * @param string $signature
     *
     * @throws VendorException
     *
     * @return array
     */
    public function checkPaymentPoll($payload, $signature = null)
    {
        // Generate the signature, if we need to.
        if (empty($signature)) {
            $signature = $this->generateSignature($payload);
        }


        // Check the signature length.
        if (strlen($signature) != 64) {
            throw new VendorException("Check payment signature length invalid");
        }


        // cURL the payload with the signature to the API.
        if ($ch = curl_init(Vendor::URL_POLL)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'ETN-SIGNATURE: ' . $signature
            ]);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $result = curl_exec($ch);
			
			
			if (curl_error($ch)) {
			    $error_msg = curl_error($ch);
				echo $error_msg;
			}
			

            curl_close($ch);
			
			
        } else {
            throw new VendorException("Check payment cURL cannot initialise");
        }

        // Return the result as an array.
        return json_decode($result, true);
    }
}
