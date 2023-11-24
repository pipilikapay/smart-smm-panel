<?php
defined('BASEPATH') or exit('No direct script access allowed');

class pipilikapay extends MX_Controller
{
	public $tb_users;
	public $tb_transaction_logs;
	public $tb_payments;
	public $tb_payments_bonuses;
	public $payment_type;
	public $payment_id;
	public $currency_code;
	public $payment_lib;
	public $api_key;
	public $secret_key;
	public $convert_rate;
	public $take_fee_from_user;

	public function __construct($payment = "")
	{
		parent::__construct();
		$this->load->model('add_funds_model', 'model');

		$this->tb_users            = USERS;
		$this->payment_type	       = 'pipilikapay';
		$this->tb_transaction_logs = TRANSACTION_LOGS;
		$this->tb_payments         = PAYMENTS_METHOD;
		$this->tb_payments_bonuses = PAYMENTS_BONUSES;
		$this->currency_code       = get_option("currency_code", "USD");
		if ($this->currency_code == "") {
			$this->currency_code = 'USD';
		}

		if (!$payment) {
			$payment = $this->model->get('id, type, name, params', $this->tb_payments, ['type' => $this->payment_type]);
		}

		$this->payment_id = $payment->id;
		$params  = $payment->params;
		$option                			= get_value($params, 'option');
		$this->take_fee_from_user       = get_value($params, 'take_fee_from_user');
		// options
		$this->api_key          		= get_value($option, 'api_key');
		$this->secret_key       		    = get_value($option, 'secret_key');
		$this->panel_URL       		    = get_value($option, 'panel_URL');
		$this->convert_rate       		= get_value($option, 'convert_rate');

		$this->load->library("pipilikapayapi");
		$this->payment_lib = new pipilikapayapi($this->api_key, $this->secret_key, $this->panel_URL);
	}

	public function index()
	{
		redirect(cn("add_funds"));
	}

	/**
	 *
	 * Create payment
	 *
	 */
	public function create_payment($data_payment = "")
	{

		_is_ajax($data_payment['module']);
		$amount = $data_payment['amount'];

		if (!$amount) {
			_validation('error', lang('There_was_an_error_processing_your_request_Please_try_again_later1'));
		}

		if (!$this->api_key || !$this->secret_key) {
			_validation('error', lang('this_payment_is_not_active_please_choose_another_payment_or_contact_us_for_more_detail2'));
		}

		$users  = session('user_current_info');
		$unique_id = uniqid();
		$full_name = $users['first_name'] . $users['last_name'];
		$data   = array(
			"full_name" 		=> (isset($full_name)) ? $full_name : 'John Doe',
			"email"             => $users['email'],
			"amount" 			=> $amount * $this->convert_rate,
			"currency" 			=> $this->currency_code,
			"payment_id"        => $this->payment_id,
			'unique_id'         => $unique_id,
			"description" 		=> lang('Deposit_to_') . get_option('website_name') . '. (' . $users['email'] . ')',
			"redirect_url" 		=> cn("add_funds/success"),
			"cancel_url" 		=> cn("add_funds/unsuccess"),
			"webhook_url" 		=> cn("add_funds/pipilikapay/complete"),
		);

		$response = $this->payment_lib->create_payment($data);
		if (isset($response['status'])) {

			$data_tnx_log = array(
				"ids" 				=> ids(),
				"uid" 				=> session("uid"),
				"type" 				=> $this->payment_type,
				"transaction_id" 	=> $unique_id,
				"amount" 	        => $amount,
				"status" 	        => 0,
				"created" 			=> NOW,
			);

			$transaction_log_id = $this->db->insert($this->tb_transaction_logs, $data_tnx_log);
			$transaction_id = $this->db->insert_id();
			set_session("transaction_id", $transaction_id);
			if ($this->input->is_ajax_request()) {
				ms(['status' => 'success', 'redirect_url' => $response['paymentURL']]);
			}

		} else {
			_validation('error', lang('There_was_an_error_processing_your_request_Please_try_again_later3'.$response));
		}
	}

	/**
	 *
	 * Call Execute payment after creating payment
	 *
	 */
	public function complete()
	{

		$result = $this->payment_lib->execute_payment();

		if (isset($result['status']) && $result['status'] == 'Completed') {

	        $apiKey= $this->api_key;
	        $secretKey= $this->secret_key;
	        $panel_URL= $this->panel_URL;

		    $requestbody = array(
		        'apiKey' => $apiKey,
		        'secretkey' => $secretKey,
		        'paymentID' => $result['payment_id']
		    );
		    $url = curl_init("$panel_URL/payment/api/verify_payment");                     
        
            curl_setopt($url, CURLOPT_POST, 1);
            curl_setopt($url, CURLOPT_POSTFIELDS, $requestbody);
            curl_setopt($url, CURLOPT_RETURNTRANSFER, true);
		    $resultdata = curl_exec($url);
		    curl_close($url);

		    $resultdata = json_decode($resultdata, true);

		    if($resultdata['PaymentStatus'] == "Completed")
				$transaction = $this->model->get('*', $this->tb_transaction_logs, ['transaction_id' => $result['metadata']['orderID'], 'status' => 0, 'type' => $this->payment_type]);
				if (!$transaction) {
					die('Invalid Transaction ID');
				}

				$data_tnx_log = array(
					"transaction_id" 	=> $result['transaction_id'],
					"amount" 	        => $result['amount'] / $this->convert_rate,
					'txn_fee'           => 0,
					'payer_email'       => $result['email'],
					"status" 			=> 1,
				);

				$this->db->update($this->tb_transaction_logs, $data_tnx_log, ['id' => $transaction->id]);

				$transaction_fee = 0;
				// Canculate new funds
				if ($this->take_fee_from_user) {
					$transaction->txn_fee  = $transaction_fee;
				} else {
					$transaction->txn_fee = 0;
				}

				// Update Balance
				$this->model->add_funds_bonus_email($transaction, $this->payment_id);
				die('Success');
		}
	}
}
