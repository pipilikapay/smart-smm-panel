<?php defined('BASEPATH') OR exit('No direct script access allowed');

class pipilikapayapi {
    
    private $api_key;
    private $secret_key;
    private $panel_URL;
    
    
    
    public function __construct($api_key = null, $secret_key = null, $panel_URL = null) {
        if ($api_key != "" && $secret_key != "") {
            $this->api_key = $api_key;
            $this->secret_key = $secret_key;
            $this->panel_URL = $panel_URL;
        }
    }

    /**
     *
     * Define Payment && Create payment.
     *
     */
    public function create_payment($data = ""){
        // Setup request to send json via POST.

        $callbackURL= $data['redirect_url'];
        $webhookURL= $data['webhook_url'];
        $cancelURL= $data['cancel_url'];

        $apiKey= $this->api_key;
        $secretKey= $this->secret_key;
        $panel_URL= $this->panel_URL;

        $metadata = array(
            'customerID' => $data['payment_id'],
            'orderID' => $data['unique_id']
        );

        $requestbody = array(
            'apiKey' => $apiKey,
            'secretkey' => $secretKey,
            'amount' => $data['amount'],
            'fullname' => $data['full_name'],
            'email' => $data['email'],
            'successurl' => $callbackURL,
            'webhookUrl' => $webhookURL,
            'cancelurl' => $cancelURL,
            'metadata' => json_encode($metadata)
        );
        $url = curl_init("$panel_URL/payment/api/create_payment");                     
        curl_setopt($url, CURLOPT_POST, 1);
        curl_setopt($url, CURLOPT_POSTFIELDS, $requestbody);
        curl_setopt($url, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($url);
        curl_close($url);
        
        $result = json_decode($result, true);

        return $result;

    }

    /**
     *
     * Execute payment 
     *
     */
    public function execute_payment(){

        $response = file_get_contents('php://input');

        if (!empty($response)) {

            // Decode response data
            $data     = json_decode($response, true);

            if (is_array($data)) {
                return $data;
            }
        }

        return [
            'status'    => false,
            'message'   => 'Invalid response from pipilikapay API.'
        ];
    }
}








