<?php
/**
 * PayPalRestfulApi.php communications class for PayPal Rest payment module
 *
 * Applicable PayPal documentation:
 *
 * - https://developer.paypal.com/docs/checkout/advanced/processing/
 * - https://stackoverflow.com/questions/14451401/how-do-i-make-a-patch-request-in-php-using-curl
 * - https://developer.paypal.com/docs/checkout/standard/customize/
 *
 * @copyright Copyright 2023 Zen Cart Development Team
 * @license https://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version $Id: lat9 2023 Nov 16 Modified in v2.0.0 $
 */

/**
 * PayPal REST API (see https://developer.paypal.com/api/rest/)
 */
class PayPalRestfulApi extends base
{
    // -----
    // Constants used to set the class variable errorInfo['errNum'].
    //
    public const ERR_NO_ERROR      = 0;    //-No error occurred, initial value

    public const ERR_NO_CHANNEL    = -1;   //-Set if the curl_init fails; no other requests are honored
    public const ERR_CURL_ERROR    = -2;   //-Set if the curl_exec fails.  The curlErrno variable contains the curl_errno and errMsg contains curl_error
    
    public const ERR_CANT_UPDATE   = -100; //-Set by updateOrder if updated parameters aren't valid.

    // -----
    // Constants that define the test and production endpoints for the API requests.
    //
    protected const ENDPOINT_SANDBOX = 'https://api-m.sandbox.paypal.com/';
    protected const ENDPOINT_PRODUCTION = 'https://api-m.paypal.com/';

    // -----
    // Constants used to encrypt the session-based copy of the access-token.  Used by
    // the getSavedToken/saveToken methods.
    //
    private const ENCRYPT_ALGO = 'AES-256-CBC';

    // -----
    // PayPal constants associated with an order's current 'status'.
    //
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_CAPTURED = 'CAPTURED';
    public const STATUS_COMPLETED = 'COMPLETED';
    public const STATUS_CREATED = 'CREATED';
    public const STATUS_DENIED = 'DENIED';
    public const STATUS_FAILED = 'FAILED';
    public const STATUS_PARTIALLY_REFUNDED = 'PARTIALLY_REFUNDED';

    //- The order requires an action from the payer (e.g. 3DS authentication). Redirect the payer to the "rel":"payer-action" HATEOAS
    //    link returned as part of the response prior to authorizing or capturing the order.
    public const STATUS_PAYER_ACTION_REQUIRED = 'PAYER_ACTION_REQUIRED';

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_REFUNDED = 'REFUNDED';
    public const STATUS_SAVED = 'SAVED';
    public const STATUS_VOIDED = 'VOIDED';


    // -----
    // Variable that holds the selected cryptographic algorithm and its IV length.
    // Set during construction.
    //
    private $encryptionAlgorithm;
    private $encryptionAlgoIvLen;

    /**
     * Variables associated with interface logging;
     *
     * @logFile string
     * @debug bool
     */
    protected $debug = false;
    protected $debugLogFile;

    /**
     * Sandbox or production? Set during class construction.
     */
    protected $endpoint;

    /**
     * OAuth client id and secret, set during class construction.
     */
    private $clientId;
    private $clientSecret;
    
    /**
     * The CURL channel, initialized during construction.
     */
    protected $ch = false;

    /**
     * Options for cURL. Defaults to preferred (constant) options.  Used by
     * the curlGet and curlPost methods.
     */
    protected $curlOptions = [
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_FORBID_REUSE => true,
        CURLOPT_FRESH_CONNECT => true,
        CURLOPT_HEADER => 0,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45,
    ];

    /**
     * Error information, when a CURL or RESTful error occurs.
     */
    protected $errorInfo = [
        'errMsg' => '',
        'errNum' => 0,
        'curlErrno' => 0,
        'httpCode' => 200,
        'details' => [],
    ];

    /**
     * Array, used by the getOrderDifference method, that identifies what operations
     * can be performed on various fields of the order's requested data.  All
     * 'keys' are part of the 'purchase_units' array of that request-data!
     *
     * The 'key' values can be a pseudo-encoding of the an array structure
     * present in the 'purchase_units' array.  For example, the 'shipping.name'
     * key represents the shipping array's sub-element 'name'.
     */
    protected $orderUpdateOperations = [
        'custom_id' => 'replace, add, remove',
        'description' => 'replace, add, remove',
        'shipping.name' => 'replace, add',
        'shipping.address' => 'replace, add',
        'shipping.type' => 'replace, add',
        'soft_descriptor' => 'replace, remove',
        'amount' => 'replace',
        'items' => 'replace, add, remove',
        'invoice_id' => 'replace, add, remove',
    ];

    // -----
    // Class constructor, saves endpoint (production vs. sandbox), clientId and clientSecret
    //
    public function __construct(string $endpoint_type, string $client_id, string $client_secret, bool $enable_debug)
    {
        $this->endpoint = ($endpoint_type === 'Production') ? self::ENDPOINT_PRODUCTION : self::ENDPOINT_SANDBOX;
        $this->clientId = $client_id;
        $this->clientSecret = $client_secret;
        $this->encryptionAlgorithm = $this->setEncryptionAlgorithm();
        $this->encryptionAlgoIvLen = openssl_cipher_iv_length($this->encryptionAlgorithm);

        $this->ch = curl_init();
        if ($this->ch === false) {
            $this->setErrorInfo(self::ERR_NO_CHANNEL, 'Unable to initialize the CURL channel.');
            trigger_error($this->errMsg, E_USER_WARNING);
        }

        $this->debug = $enable_debug;
        $this->debugLogFile = DIR_FS_LOGS . '/PayPalRestfulApi-' . ($_SESSION['customer_id'] ?? 'guest') . '-' . date('Ymd') . '.log';

        $this->notify('NOTIFY_PAYPALRESTFULAPI_CONSTRUCT', $endpoint_type);
    }
    protected function setEncryptionAlgorithm(): string
    {
        return self::ENCRYPT_ALGO;
    }

    // ----
    // Class destructor, close the CURL channel if the channel's open (i.e. not false).  Also an 'alias' for the
    // public 'close' method.
    //
    public function __destruct()
    {
        $this->close();
    }
    public function close()
    {
        if ($this->ch !== false) {
            curl_close($this->ch);
            $this->ch = false;
        }
    }

    // ===== Start Non-token Methods =====

    public function createOrder(array $order_request)
    {
        $this->debugLog('==> Start createOrder', true);
        $response = $this->curlPost('v2/checkout/orders', $order_request);
        $this->debugLog("==> End createOrder\n", true);
        return $response;
    }

    public function getOrderStatus(string $paypal_order_id)
    {
        $this->debugLog('==> Start getOrderStatus', true);
        $response = $this->curlGet("v2/checkout/orders/$paypal_order_id");
        $this->debugLog("==> End getOrderStatus\n", true);
        return $response;
    }

    public function confirmPaymentSource(string $paypal_order_id, array $payment_source)
    {
        $this->debugLog('==> Start confirmPaymentSource', true);
        $paypal_options = [
            'payment_source' => $payment_source,
        ];
        $response = $this->curlPost("v2/checkout/orders/$paypal_order_id/confirm-payment-source", $paypal_options);
        $this->debugLog("==> End confirmPaymentSource\n", true);
        return $response;
    }

    public function captureOrder(string $paypal_order_id)
    {
        $this->debugLog('==> Start captureOrder', true);
        $response = $this->curlPost("v2/checkout/orders/$paypal_order_id/capture");
        $this->debugLog("==> End captureOrder\n", true);
        return $response;
    }

    public function authorizeOrder(string $paypal_order_id)
    {
        $this->debugLog('==> Start authorizeOrder', true);
        $response = $this->curlPost("v2/checkout/orders/$paypal_order_id/authorize");
        $this->debugLog("==> End authorizeOrder\n", true);
        return $response;
    }

    public function getAuthorizationDetails(string $paypal_auth_id)
    { 
        $this->debugLog('==> Start getAuthorizationDetails', true);
        $response = $this->curlPost("v2/payments/authorizations/$paypal_auth_id");
        $this->debugLog("==> End getAuthorizationDetails\n", true);
        return $response;
    }

    public function capturePayment(string $paypal_auth_id, string $invoice_id, string $payer_note, )
    { 
        $this->debugLog('==> Start capturePayment', true);
        $response = $this->curlPost("v2/payments/authorizations/$paypal_auth_id/capture");
        $this->debugLog("==> End capturePayment\n", true);
        return $response;
    }

    public function reAuthorizePayment(string $paypal_auth_id, string $currency_code, string $value)
    { 
        $this->debugLog('==> Start reAuthorizePayment', true);
        $amount = [
            'amount' => [
                'currency_code' => $currency_code,
                'value' => $value,
            ],
        ];
        $response = $this->curlPost("v2/payments/authorizations/$paypal_auth_id/reauthorize", $amount);
        $this->debugLog("==> End reAuthorizePayment\n", true);
        return $response;
    }

    public function voidPayment(string $paypal_auth_id)
    { 
        $this->debugLog('==> Start voidPayment', true);
        $response = $this->curlPost("v2/payments/authorizations/$paypal_auth_id/void");
        $this->debugLog("==> End voidPayment\n", true);
        return $response;
    }

    public function getCapturedDetails(string $paypal_capture_id)
    { 
        $this->debugLog('==> Start getCapturedDetails', true);
        $response = $this->curlPost("v2/payments/captures/$paypal_capture_id");
        $this->debugLog("==> End getCapturedDetails\n", true);
        return $response;
    }
    
    public function refundCaptureFull(string $paypal_capture_id)
    {
    }
    public function refundCapturePartial(string $paypal_capture_id, string $currency_code, string $value)
    {
    }
    protected function refundCapture($paypal_capture_id, array $amount)
    {
    }

    // -----
    // Update a PayPal order with CREATED or APPROVED status **only**.
    //
    // Parameters:
    // - paypal_order_id
    //      The 'id' value returned by PayPal when the order was created or approved.
    // - order_request_current
    //      The order-request's current contents, presumed to be those recorded at PayPal.
    // - order_request_update
    //      The to-be-updated contents for the order.
    //
    // Return Values:
    // - response
    //      Returns false if an error is detected; the caller retrieves the error details via the
    //          getErrorInfo method.
    //      On success, returns an associative array containing the PayPal response.
    //
    public function updateOrder(string $paypal_order_id, array $order_request_current, array $order_request_update)
    {
        $this->debugLog("==> Start updateOrder ($paypal_order_id).  Current:\n" . $this->logJSON($order_request_current) . "\nUpdate:\n" . $this->logJSON($order_request_update), true);

        // -----
        // Check to see that the order is valid and that its status is also valid to perform an update operation.
        //
        $status = $this->getOrderStatus($paypal_order_id);
        if ($status === false) {
            return false;
        }
        if ($status['status'] !== self::STATUS_CREATED && $status['status'] !== self::STATUS_APPROVED) {
            $this->setErrorInfo(422, '', 0, ['name' => 'ORDER_ALREADY_COMPLETED', 'message' => 'The order cannot be patched after it is completed.']);
            $this->debugLog("  --> Can't update, due to order status restriction: '{$status['status']}'.");
            return false;
        }

        $updates = $this->getOrderDifference($order_request_current, $order_request_update);
        if (count($updates) === 0) {
            $this->debugLog('  --> Nothing to update, returning getOrderStatus.');
            return $status;
        }
        if ($updates[0] === 'error') {
            return false;
        }

        $this->debugLog("Updates to order:\n" . $this->logJSON($updates));
        $order_updates = [];
        foreach ($updates as $next_update) {
            $order_updates[] = [
                'op' => $next_update['op'],
                'path' => "/purchase_units/@reference_id=='default'/{$next_update['path']}",
                'value' => $next_update['value'],
            ];
        }
        $response = $this->curlPatch("v2/checkout/orders/$paypal_order_id", $order_updates);

        $this->debugLog('==> End updateOrder', true);
        return $response;
    }
    protected function getOrderDifference(array $current, array $update): array
    {
        // -----
        // Determine *all* differences between a current PayPal order and the
        // current update.  If no differences, return an empty array.
        //
        $purchase_unit_current = $current['purchase_units'][0];
        $purchase_unit_update = $update['purchase_units'][0];
        $order_difference = $this->orderDiffRecursive($purchase_unit_current, $purchase_unit_update);
        if (count($order_difference) === 0) {
            return [];
        }

        $difference = [];
        foreach ($this->orderUpdateOperations as $key => $update_options) {
            $subkey = '';
            if (strpos($key, '.') !== false) {
                [$key, $subkey] = explode('.', $key);
            }

            // -----
            // Remove this valid-to-update key{/subkey} element from the overall orders'
            // differences.  If any differences remain after this loop's processing, then
            // there are updates to the order that are disallowed by PayPal.
            //
            if ($subkey !== '') {
                unset($order_difference[$key][$subkey]);
            } else {
                unset($order_difference[$key]);
            }

            // -----
            // Remove the current [$key] or [$key][$subkey] from the overall differences
            // between the two order-information arrays submitted.  If
            $key_subkey_current = $this->issetKeySubkey($key, $subkey, $purchase_unit_current);
            $key_subkey_update = $this->issetKeySubkey($key, $subkey, $purchase_unit_update);

            // -----
            // Is the field *not* present in either the currently-recorded order
            // at PayPal or in the update, nothing further to do for this key/subkey.
            //
            if ($key_subkey_current === false && $key_subkey_update === false) {
                continue;
            }

            // -----
            // Initially, nothing to do for this key/subkey element.
            //
            $op = '';

            // -----
            // If the field is present in both the current and to-be-updated order, check
            // to see if the field's changed.
            //
            if ($key_subkey_current === true && $key_subkey_update === true) {
                if ($subkey !== '') {
                    if ($purchase_unit_current[$key][$subkey] !== $purchase_unit_update[$key][$subkey]) {
                        $op = 'replace';
                        $path = "$key/$subkey";
                        $value = $purchase_unit_update[$key][$subkey];
                    }
                } elseif ($purchase_unit_current[$key] !== $purchase_unit_update[$key]) {
                        $op = 'replace';
                        $path = $key;
                        $value = $purchase_unit_update[$key];
                    }
            // -----
            // Is the field added to the order for an update?
            //
            } elseif ($key_subkey_update === true) {
                $op = 'add';
                if ($subkey !== '') {
                    $path = "$key/$subkey";
                    $value = $purchase_unit_update[$key][$subkey];
                } else {
                    $path = $key;
                    $value = $purchase_unit_update[$key];
                }
            // -----
            // Otherwise, the field was removed from the to-be-updated order.
            //
            } else {
                $op = 'remove';
                if ($subkey !== '') {
                    $path = "$key/$subkey";
                    $value = $purchase_unit_current[$key][$subkey];
                } else {
                    $path = $key;
                    $value = $purchase_unit_current[$key];
                }
            }

            // -----
            // If no change to the current key/subkey was found, continue on
            // to the next key/subkey check.
            //
            if ($op === '') {
                continue;
            }

            // -----
            // The current key/subkey was changed in some manner, make sure that
            // the operation is allowed by PayPal.  If not, return a 'difference'
            // that indicates that the update cannot be applied.
            //
            if (strpos($update_options, $op) === false) {
                $error_message = "$key/$subkey operation '$op' is not supported";
                $this->setErrorInfo(self::ERR_CANT_UPDATE, $error_message);
                $this->debugLog('--> Update disallowed: ' . $error_message);
                return ['error'];
            }

            // -----
            // The current key/subkey was changed and it's allowed, note the difference
            // in the to-be-returned difference array.
            //
            $difference[] = [
                'op' => $op,
                'path' => $path,
                'value' => $value,
            ];
        }

        // -----
        // If any elements remain in the orders' overall difference array, then those
        // elements aren't valid-to-update by PayPal.  Note the condition in the PayPal
        // log and the errorInfo; return a 'difference' that indicates that the update
        // cannot be applied.
        //
        if (count($order_difference) !== 0) {
            $this->setErrorInfo(self::ERR_CANT_UPDATE, 'Parameter error, order cannot be updated using current parameters');
            $this->debugLog("--> Update disallowed, changed parameters cannot be updated:\n" . $this->logJSON($order_difference));
            return ['error'];
        }

        return $difference;
    }
    protected function issetKeySubkey(string $key, string $subkey, array $array1): bool
    {
        return ($subkey !== '') ? isset($array1[$key][$subkey]) : isset($array1[$key]);
    }
    public function orderDiffRecursive(array $current, array $update): array
    {
        $difference = [];
        foreach ($current as $key => $value) {
            if (is_array($value)) {
                if (!isset($update[$key]) || !is_array($update[$key])) {
                    $difference[$key] = $value;
                } else {
                    $new_diff = $this->orderDiffRecursive($value, $update[$key]);
                    if (!empty($new_diff)) {
                        $difference[$key] = $new_diff;
                    }
                }
            } elseif (!array_key_exists($key, $update) || $update[$key] !== $value) {
                $difference[$key] = $value;
            }
        }
        return $difference;
    }

    // ===== End Non-token Methods =====

    // ===== Start Token Handling Methods =====

    // -----
    // Validates the supplied client-id/secret; used during admin initialization to
    // auto-disable the associated payment method if the credentials aren't valid.
    //
    public function validatePayPalCredentials(string $client_id, string $client_secret): bool
    {
        return ($this->getOAuth2Token($client_id, $client_secret, false) !== '');
    }

    // -----
    // Retrieves an OAuth token from PayPal to use in follow-on requests, returning the token
    // to the caller.
    //
    // Normally, the method's called without the 3rd parameter, so it will check to see if a
    // previously-saved token is available to cut down on API calls.  The validatePayPalCredentials
    // method is an exclusion, as it's used during the admin configuration of the payment module to
    // ensure that the client id/secret are validated.
    //
    protected function getOAuth2Token(string $client_id, string $client_secret, bool $use_saved_token = true): string
    {
        if ($this->ch === false) {
            $this->ch = curl_init();
            if ($this->ch === false) {
                $this->setErrorInfo(self::ERR_NO_CHANNEL, 'Unable to initialize the CURL channel.');
                return '';
            }
        }

        if ($use_saved_token === true) {
            $token = $this->getSavedToken();
            if ($token !== '') {
                return $token;
            }
        }

        $additional_curl_options = [
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Authorization: Basic ' . base64_encode($client_id . ':' . $client_secret),
            ]
        ];
        $response = $this->curlPost('v1/oauth2/token', ['grant_type' => 'client_credentials'], $additional_curl_options, false);

        $token = '';
        if ($response !== false) {
            $token = $response['access_token'];
            if ($use_saved_token === true) {
                $this->saveToken($token, $response['expires_in']);
            }
         }

        return $token;
    }
    protected function getSavedToken(): string
    {
        if (!isset($_SESSION['PayPalRestful']['token_expires_ts'], $_SESSION['PayPalRestful']['saved_token']) || time() > $_SESSION['PayPalRestful']['token_expires_ts']) {
            $this->clearToken();
            return '';
        }

        $this->debugLog('getSavedToken: Using saved access-token.');

        $encrypted_token = $_SESSION['PayPalRestful']['saved_token'];
        $iv = substr($encrypted_token, 0, $this->encryptionAlgoIvLen);
        $saved_token = openssl_decrypt(substr($encrypted_token, $this->encryptionAlgoIvLen), $this->encryptionAlgorithm, $this->clientSecret, 0, $iv);
        if ($saved_token === false) {
            $saved_token = '';
            $this->debugLog('getSavedToken: Failed decryption.');
            $this->clearToken();
        }
        return $saved_token;
    }
    protected function saveToken(string $access_token, int $seconds_to_expiration)
    {
        $iv = openssl_random_pseudo_bytes($this->encryptionAlgoIvLen);
        $_SESSION['PayPalRestful']['saved_token'] = $iv . openssl_encrypt($access_token, $this->encryptionAlgorithm, $this->clientSecret, 0, $iv);
        $_SESSION['PayPalRestful']['token_expires_ts'] = time() + $seconds_to_expiration;
    }
    protected function clearToken()
    {
        unset($_SESSION['PayPalRestful']['token_expires_ts'], $_SESSION['PayPalRestful']['saved_token']);
    }

    // -----
    // Sets the common authorization header into the CURL options for a PayPal Restful request.
    //
    // If the request to retrieve the token fails, an empty array is returned; otherwise,
    // the authorization-header containing the successfully-retrieved token is merged into
    // supplied array of CURL options and returned.
    //
    protected function setAuthorizationHeader(array $curl_options): array
    {
        $oauth2_token = $this->getOAuth2Token($this->clientId, $this->clientSecret);
        if ($oauth2_token === '') {
            return [];
        }

        $curl_options[CURLOPT_HTTPHEADER] = [
            'Content-Type: application/json',
            "Authorization: Bearer $oauth2_token",
            'Prefer: return=representation',
        ];
        return $curl_options;
    }

    // ===== End Token Handling Methods =====

    // ===== Start CURL Interface Methods =====

    // -----
    // A common method for all POST requests to PayPal.
    //
    // Parameters:
    // - option
    //     The option to be performed, e.g. v2/checkout/orders
    // - options_array
    //     An (optional) array of options to be supplied, dependent on the 'option' to be sent.
    // - additional_curl_options
    //     An array of additional CURL options to be applied.
    // - token_required
    //     An indication as to whether/not an authorization header is to be include.
    //
    // Return Values:
    // - On success, an associative array containing the PayPal response.
    // - On failure, returns false.  The details of the failure can be interrogated via the getErrorInfo method.
    //
    protected function curlPost(string $option, array $options_array = [], array $additional_curl_options = [], bool $token_required = true)
    {
        if ($this->ch === false) {
            $this->ch = curl_init();
            if ($this->ch === false) {
                $this->setErrorInfo(self::ERR_NO_CHANNEL, 'Unable to initialize the CURL channel.');
                return false;
            }
        }

        $url = $this->endpoint . $option;
        $curl_options = array_replace($this->curlOptions, [CURLOPT_POST => true, CURLOPT_URL => $url], $additional_curl_options);

        // -----
        // If a token is required, i.e. it's not a request to gather an access-token, use
        // the existing token to set the request's authorization.  Note that the method
        // being called will check to see if the current token has expired and will request
        // an update, if needed.
        //
        // Set the CURL options to use for this current request and then, if the token is NOT
        // required (i.e. the request is to retrieve an access-token), remove the site's
        // PayPal credentials from the posted options so that they're not exposed in subsequent
        // API logs.
        //
        if ($token_required === false) {
            if (count($options_array) !== 0) {
                $curl_options[CURLOPT_POSTFIELDS] = http_build_query($options_array);
            }
        } else {
            $curl_options = $this->setAuthorizationHeader($curl_options);
            if (count($curl_options) === 0) {
                return false;
            }
            if (count($options_array) !== 0) {
                $curl_options[CURLOPT_POSTFIELDS] = json_encode($options_array);
            }
        }

        curl_setopt_array($this->ch, $curl_options);
        if ($token_required === false) {
            unset($curl_options[CURLOPT_POSTFIELDS]);
        }
        return $this->issueRequest('curlPost', $option, $curl_options);
    }

    // -----
    // A common method for all GET requests to PayPal.
    //
    // Parameters:
    // - option
    //      The option to be performed, e.g. v2/checkout/orders/{id}
    // - options_array
    //      An (optional) array of options to be supplied, dependent on the 'option' to be sent.
    //
    // Return Values:
    // - On success, an associative array containing the PayPal response.
    // - On failure, returns false.  The details of the failure can be interrogated via the getErrorInfo method.
    //
    protected function curlGet($option, $options_array = [])
    {
        if ($this->ch === false) {
            $this->ch = curl_init();
            if ($this->ch === false) {
                $this->setErrorInfo(self::ERR_NO_CHANNEL, 'Unable to initialize the CURL channel.');
                return false;
            }
        }

        $url = $this->endpoint . $option;
        if (count($options_array) !== 0) {
            $url .= '?' . http_build_query($options_array);
        }
        curl_reset($this->ch);
        $curl_options = array_replace($this->curlOptions, [CURLOPT_HTTPGET => true, CURLOPT_URL => $url]);  //-HTTPGET Needed since we might be toggling between GET and POST requests
        $curl_options = $this->setAuthorizationHeader($curl_options);
        if (count($curl_options) === 0) {
            return null;
        }

        curl_setopt_array($this->ch, $curl_options);
        return $this->issueRequest('curlGet', $option, $curl_options);
    }

    // -----
    // A common method for all PATCH requests to PayPal.
    //
    // Parameters:
    // - option
    //     The option to be performed, e.g. v2/checkout/orders/{id}
    // - options_array
    //     An (optional) array of options to be supplied, dependent on the 'option' to be sent.
    //
    // Return Values:
    // - On success, an associative array containing the PayPal response.
    // - On failure, returns false.  The details of the failure can be interrogated via the getErrorInfo method.
    //
    //
    protected function curlPatch($option, $options_array = [])
    {
        if ($this->ch === false) {
            $this->ch = curl_init();
            if ($this->ch === false) {
                $this->setErrorInfo(self::ERR_NO_CHANNEL, 'Unable to initialize the CURL channel.');
                return false;
            }
        }

        $url = $this->endpoint . $option;
        $curl_options = array_replace($this->curlOptions, [CURLOPT_POST => true, CURLOPT_CUSTOMREQUEST => 'PATCH', CURLOPT_URL => $url]);
        $curl_options = $this->setAuthorizationHeader($curl_options);
        if (count($curl_options) === 0) {
            return false;
        }

        if (count($options_array) !== 0) {
            $curl_options[CURLOPT_POSTFIELDS] = json_encode($options_array);
        }
        curl_setopt_array($this->ch, $curl_options);
        return $this->issueRequest('curlPatch', $option, $curl_options);
    }

    protected function issueRequest(string $request_type, string $option, array $curl_options)
    {
        // -----
        // Issue the CURL request.
        //
        $curl_response = curl_exec($this->ch);

        // -----
        // If a CURL error is indicated, call the common error-handling method to record that error.
        //
        if ($curl_response === false) {
            $response = false;
            $this->handleCurlError($request_type, $option, $curl_options);
        // -----
        // Otherwise, a response was returned.  Call the common response-handler to determine
        // whether or not an error occurred.
        //
        } else {
            $response = $this->handleResponse($request_type, $option, $curl_options, $curl_response);
        }
        return $response; 
    }

    // -----
    // Protected method, called by curlGet and curlPost when the curl_exec itself
    // returns an error.  Set the internal variables to capture the error information
    // and log (if enabled) to the PayPal logfile.
    //
    protected function handleCurlError(string $method, string $option, array $curl_options)
    {
        $this->setErrorInfo(self::ERR_CURL_ERROR, curl_error($this->ch), curl_errno($this->ch));
        curl_reset($this->ch);
        $this->debugLog("handleCurlError for $method ($option) : CURL error (" . $this->logJSON($this->errorInfo) . "\nCURL Options:\n" . $this->logJSON($curl_options));
    }

    // -----
    // Protected method, called by curlGet and curlPost when no CURL error is reported.
    //
    // We'll check the HTTP response code returned by PayPal and take possibly option-specific
    // actions.
    //
    // Returns false if an error is detected, otherwise an associative array containing
    // the PayPal response.
    //
    protected function handleResponse(string $method, string $option, array $curl_options, $response)
    {
        // -----
        // Decode the PayPal response into an associative array, retrieve the httpCode associated
        // with the response and 'reset' the errorInfo property.
        //
        $response = json_decode($response, true);
        $httpCode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
        $this->setErrorInfo($httpCode, '', 0, []);

        // -----
        // If no error, simply return the associated response.
        //
        // 200: Request succeeded
        // 201: A POST method successfully created a resource.
        // 204: No content returned; implies successful completion of an updateOrder request.
        //
        if ($httpCode === 200 || $httpCode === 201 || $httpCode === 204) {
            $this->debugLog("The $method ($option) request was successful ($httpCode).\n" . $this->logJSON($response));
            return $response;
        }

        $errMsg = '';
        switch ($httpCode) {
            // -----
            // 401: The access token has expired, noting that this "shouldn't" happen.
            //
            case 401:
                $this->clearToken();
                $errMsg = 'An expired-token error was received.';
                trigger_error($errMsg, E_USER_WARNING);
                break;

            // -----
            // 400: A general, usually interface-related, error occurred.
            // 403: Permissions error, the client doesn't have access to the requested endpoint.
            // 404: Something was not found.
            // 422: Unprocessable entity, kind of like 400.
            // 429: Rate Limited (you're making too many requests too quickly; you should reduce your rate of requests to stay within our Acceptable Useage Policy)
            // 500: Server Error
            // 503: Service Unavailable (our machine is currently down for maintenance; try your request again later)
            //
            case 400:
            case 403:
            case 404:
            case 422:
            case 429:
            case 500:
            case 503:
                break;

            // -----
            // Anything else wasn't expected.  Create a warning-level log indicating the
            // issue and that the response wasn't 'valid' and indicate that the
            // slamming timeout has started for some.
            //
            default:
                $errMsg = "An unexpected response ($httpCode) was returned from PayPal.";
                trigger_error($errMsg, E_USER_WARNING);
                break;
        }
        
        // -----
        // Note the error information in the errorInfo array, log a message to the PayPal log and
        // let the caller know that the request was unsuccessful.
        //
        $this->setErrorInfo($httpCode, $errMsg, 0, $response);
        $this->debugLog("The $method ($option) request was unsuccessful.\n" . $this->logJSON($this->errorInfo) . "\nCURL Options: " . $this->logJSON($curl_options));

        return false;
    }

    protected function setErrorInfo(int $errNum, string $errMsg, int $curlErrno = 0, $response = [])
    {
        $name = $response['name'] ?? 'n/a';
        $message = $response['message'] ?? 'n/a';
        $details = $response['details'] ?? 'n/a';
        $this->errorInfo = compact('errNum', 'errMsg', 'curlErrno', 'name', 'message', 'details');
    }

    public function getErrorInfo(): array
    {
        return $this->errorInfo;
    }

    // ===== End CURL Interface Methods =====

    // ===== Start Logging Methods =====

    public function getLogFileName(): string
    {
        return $this->debugLogFile;
    }

    // -----
    // Format pretty-printed JSON for the debug-log, removing any HTTP Header
    // information (present in the CURL options) and/or the actual access-token.
    //
    // Also remove unneeded return values that will just 'clutter up' the logged information.
    //
    protected function logJSON($data)
    {
        if (is_array($data)) {
            unset(/*$data[CURLOPT_HTTPHEADER], $data['access_token'],*/ $data['scope'], $data['links']);
        }
        return json_encode($data, JSON_PRETTY_PRINT);
    }

    protected function debugLog($message, $include_timestamp = false)
    {
        global $current_page_base;

        if ($this->debug === true) {
            $timestamp = ($include_timestamp === false) ? '' : ("\n" . date('Y-m-d H:i:s: ') . "($current_page_base) ");
            error_log($timestamp . $message . PHP_EOL, 3, $this->debugLogFile);
        }
    }

    // ===== END Logging Methods =====
}