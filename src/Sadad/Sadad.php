<?php

namespace Larabookir\Gateway\Sadad;

use SoapClient;
use Larabookir\Gateway\PortAbstract;
use Larabookir\Gateway\PortInterface;

class Sadad extends PortAbstract implements PortInterface
{
	/**
	 * Url of sadad gateway web service
	 *
	 * @var string
	 */
	protected $requestUrl  = 'https://sadad.shaparak.ir/vpg/api/v0/Request/PaymentRequest';



	protected $gatewayPage = "https://sadad.shaparak.ir/VPG/Purchase";


	/**
	 * {@inheritdoc}
	 */
	public function set($amount)
	{
		$this->amount = intval($amount);

		return $this;
	}

	/**
	 * {@inheritdoc}
	 */
	public function ready()
	{
		$this->sendPayRequest();

		return $this;
	}

	/**
	 * {@inheritdoc}
	 */
	public function redirect()
	{
		return \Redirect::to($this->gatewayPage."?Token={$this->token}");
	}

	/**
	 * {@inheritdoc}
	 */
	public function verify($transaction)
	{
		parent::verify($transaction);

		$this->verifyPayment();

		return $this;
	}

	/**
	 * Sets callback url
	 * @param $url
	 */
	function setCallback($url)
	{
		$this->callbackUrl = $url;
		return $this;
	}

	/**
	 * Gets callback url
	 * @return string
	 */
	function getCallback()
	{
		if (!$this->callbackUrl)
			$this->callbackUrl = $this->config->get('gateway.sadad.callback-url');

		return $this->makeCallback($this->callbackUrl, ['transaction_id' => $this->transactionId()]);
	}

	protected function encrypt_pkcs7($str, $key)
	{
		$key = base64_decode($key);
		$ciphertext = OpenSSL_encrypt($str,"DES-EDE3", $key, OPENSSL_RAW_DATA);
		return base64_encode($ciphertext);
	}

	protected function CallAPI($url, $data = false)
	{
		$curl = curl_init($url);
		curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");  
		curl_setopt($curl, CURLOPT_POSTFIELDS,$data);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json','Content-Length: ' . strlen($data)));
		$result = curl_exec($curl);
		curl_close($curl);
		return $result;
	}


	/**
	 * Send pay request to server
	 *
	 * @return void
	 *
	 * @throws SadadException
	 */
	protected function sendPayRequest()
	{
		$this->newTransaction();

		$this->form = '';

		try {
			$LocalDateTime=date("m/d/Y g:i:s a");
			$SignData=$this->encrypt_pkcs7("{$this->config->get('gateway.sadad.terminalId')};{$this->transactionId()};{$this->amount}","{$this->config->get('gateway.sadad.transactionKey')}");
			$data = array(
				'TerminalId'	=>	$this->config->get('gateway.sadad.terminalId'),
				'MerchantId'	=>	$this->config->get('gateway.sadad.merchant'),
				'Amount'		=>	$this->amount,
				'SignData'		=>	$SignData,
				'ReturnUrl'		=>	$this->getCallback(),
				'LocalDateTime'	=>	$LocalDateTime,
				'OrderId'		=>	$this->transactionId()
			);

			$str_data = json_encode($data);
			$res=$this->CallAPI('https://sadad.shaparak.ir/vpg/api/v0/Request/PaymentRequest',$str_data);
			$arrres=json_decode($res);
			if($arrres->ResCode==0)
			{
				
				$Token= $arrres->Token;
				$this->token = $Token;
			}
			else
				die($arrres->Description);



		} catch (\Exception $e) {
			$this->transactionFailed();
			$this->newLog('Exception', $e->getMessage());
			throw $e;
		}

		// if (!isset($response['RequestKey']) || !isset($response['PaymentUtilityResult'])) {
		// 	$this->newLog(SadadResult::INVALID_RESPONSE_CODE, SadadResult::INVALID_RESPONSE_MESSAGE);
		// 	throw new SadadException(SadadResult::INVALID_RESPONSE_MESSAGE, SadadResult::INVALID_RESPONSE_CODE);
		// }

		// $this->form = $response['PaymentUtilityResult'];
		$this->refId = $arrres->ResCode;

		$this->transactionSetRefId();
	
	}

	/**
	 * Verify user payment from bank server
	 *
	 * @throws SadadException
	 */
	protected function verifyPayment()
	{
		try {
			$key=$this->config->get('gateway.sadad.transactionKey');
			$OrderId=$_POST["OrderId"];
			$Token=$_POST["token"];
			$ResCode=$_POST["ResCode"];
			if($ResCode==0)
			{
				$verifyData = array('Token'=>$Token,'SignData'=>$this->encrypt_pkcs7($Token,$this->config->get('gateway.sadad.transactionKey')));
				$str_data = json_encode($verifyData);				
				$res=$this->CallAPI('https://sadad.shaparak.ir/vpg/api/v0/Advice/Verify',$str_data);
				$arrres=json_decode($res);
			}
			

		} catch (\SoapFault $e) {
			$this->transactionFailed();
			$this->newLog('SoapFault', $e->getMessage());
			throw $e;
		}

		if (empty($arrres) || !isset($arrres->SystemTraceNo))
			throw new SadadException('در دریافت اطلاعات از بانک خطایی رخ داده است.');

		// $statusResult = strval($result->AppStatusCode);
		// $appStatus = strtolower($result->AppStatusDescription);

		if($arrres->ResCode!=-1 && $ResCode==0)
		{
			var_dump($arrres);
			$this->trackingCode = $arrres->SystemTraceNo;
			$this->cardNumber = $arrres->RetrivalRefNo;
			$this->transactionSucceed();
		}
		else{
			$this->transactionFailed();
		}
		$message = $this->getMessage($arrres->ResCode, '$appStatus');

		$this->newLog($ResCode, $message['fa']);

	}

	/**
	 * Register error to error list
	 *
	 * @param int $code
	 * @param string $message
	 *
	 * @return array|null
	 *
	 * @throws SadadException
	 */
	private function getMessage($code, $message)
	{
		$result = SadadResult::codeResponse($code, $message);
		if ($result) {
			return $result;
		}
		$result = array(
			'code' => SadadResult::UNKNOWN_CODE,
			'message' => SadadResult::UNKNOWN_MESSAGE,
			'fa' => 'خطای ناشناخته',
			'en' => 'Unknown Error',
			'retry' => false
		);
		

		return $result;
	}
}
