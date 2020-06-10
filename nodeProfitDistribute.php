<?php
//ini_set('precision', 50);
/*
 * Store NAS value in address
 * Check if a address has been pledging for a full day
 * Calculate the percentage ratio for every donor
 * Distribute NAS to each address that meet the minimum requirements.
 * Store all data in a database
 *
 * Work by block height (every XX blocks the program runs)
 * Notes: 18 decimal points in NAS value
 * 9 decimal points in NAX value
 *
 * Requires bcmath (apt install php-bcmath)
 */
if (isset($argv[1])) { //&& $argv[2] == 'fromBash'
	$doProcess = $argv[1];
} else {
	echo "Nothing to do. Exiting\n";
	$doProcess = 'statusCheck';
}
include_once 'config.php';
$NebNodeProfitSharing = new NebNodeProfitSharing($config);
$NebNodeProfitSharing->doFunction($doProcess);

class NebNodeProfitSharing
{
	private $messages = []; //store any messages from the processes. All messages contain a result field which can be either success, warn, fail.
	private $NasToShare;//How much NAS to share - math: $transactionFee*numberOfAddresses
	private $voterData;//The voter data from the getVoterData function
	private $totalNAXStakedToNode;//Store the amount of NAX voted for the node
	private $numberOfAddressesVoting = 0;//How many addresses voted (this calculates how much to hold to tx fees.
	private $totalTxFees;//How much in total are we spending in tx fees (deducts from NasBalance to get the final NasToShare)
	private $processTransactions = false;//Process the transactions - true: live/send funds / false: test/no funds sent.
	private $totalEntitledNasForAll;//How much NAS should be distributed
	private $payoutCompleted = false;
	//Local node status
	private $nodeStatusRPC;
	private $nodeSynchronized;
	private $nodeBlockHeight;
	//Log settings
	private $logEchoNumber;//The log will display the entry number
	private $severityMessageArray = [0 => 'success', 1 => 'info', 2 => 'notify', 3 => 'warn', 4 => 'error'];
	private $severityMessageMax;

	private $coinbaseAddressNonce;
	private $coinbaseAddressNasBalance;//how much NAS is in the sharing address - actual number comes from the blockchain

	private $distributionAddressNonce;
	private $distributionAddressNasBalance;//how much NAS is in the sharing address - actual number comes from the blockchain
	private $distributionAddressAmountToSend;
	private $distributionAddressRemainingNas;

	//Personal storage info - where to send NAS that does not get distributed to voters
	private $personalAddressAmountToSend;


	private $processStorage = [];//Store the process and save it in the database

	//Dataset stuff
	private $lastNasDistributionTimestamp;
	private $lastNasDistributionDataset;
	private $NasDistributionDatasetFull;
	private $NasDistributionDatasetLatest;
	private $datasetsToStoreLocally = 100;
	//Execution time
	private $executionStartTime;
	private $executionEndTime;
	private $executionTimeDuration;


	private $timeInitiator;

	/*
	 * Notes
	 *  How to get how much NAS each address should receive
	 * 1) Get the current balance in the distribution address via NasBalanceInDistributionAddress()
	 * 2) Collect voter data such as how much NAX pledged via getVoterData()
	 * 3) Calculate how much NAS each address should receive via calculateNasPerAddress()
	 */
	public function doFunction($doFunction)
	{
		$this->executionStartTime = time();
		$this->nodeStatusRPCCheck();//Always get node status
		switch ($doFunction) {
			case'calculateNasPerAddress':
				$this->calculateNasPerAddress();
				break;
			case 'payout':
				$this->payout();
				break;
			case 'transferNasFromCoinbaseAddress':
				$this->transferNasFromCoinbaseAddress();
				break;
			case 'readLocalData':
				$this->readLocalData();
				break;
			case 'nodeStatusRPCCheck':
				$this->nodeStatusRPCCheck();
				break;
			case 'testPayout':
				$this->testPayout();
				break;
			default:
				break;
		}
		if ($this->payoutCompleted) {
			$this->executionEndTime = time();
			$this->localDataStorage();
		}
	}

	private function readLocalData()
	{
		if (file_exists($this->config['localDataFile'])) {
			$NasDistributionDataset = file_get_contents($this->config['localDataFile']);//Get data
			print_r(json_decode($NasDistributionDataset, true));
		} else {
			echo "There is currently no local data storage - the program has probably not executed yet.;";
		}
	}

	private function payout()
	{
		$this->timeInitiator = time();
		$this->verboseLog("Entered payout()");
		$this->NasBalanceInDistributionAddress();
		$this->getVoterData();
		$this->calculateTotalTxFees_NasToShare();
		$this->calculateNasPerAddress();
		$this->distributeNasToVotersAddresses();
		$this->viewInfo();
		$this->payoutCompleted = true;
		$this->executionEndTime = time();
		$this->executionTimeDuration = $this->executionEndTime - $this->executionStartTime;
		$this->localDataStorage();
		$this->datasetExport();
		if ($this->severityMessageMax > 4)
			$this->emailNotifier('error');
		else
			$this->emailNotifier();
	}

	private function testPayout()
	{
		$this->verboseLog("Entered testPayout()");
		$this->NasBalanceInDistributionAddress();
		$this->getVoterData();
		$this->calculateTotalTxFees_NasToShare();
		$this->calculateNasPerAddress();
		$this->payoutCompleted = false;
		$this->executionEndTime = time();
		$this->executionTimeDuration = $this->executionEndTime - $this->executionStartTime;
	}

	private function datasetExport()//Send the data to a URL
	{
		$this->verboseLog("Entered datasetExport()");
		if ($this->config['exportDatasetToUrl'] == true) {
			$datasetLogin['exportLogin'] = ['exportDatasetUsername'   => $this->config['exportDatasetUsername'],
			                                'exportDatasetPrivateKey' => $this->config['exportDatasetPrivateKey']];
			$dataset = array_merge($this->NasDistributionDatasetLatest, $datasetLogin);
			$curlRequest = $this->curlRequest($this->config['exportDatasetURL'], json_encode($dataset), 30);//json_encode($dataset)
			$this->verboseLog("curlRequest");
			$this->verboseLog($curlRequest);
		}
	}

	private function localDataStorage($req = 'readLatest')
	{
		$this->verboseLog("Entered localDataStorage()");
		if (!file_exists($this->config['localDataFile'])) { //Set the initial file if it does not exist
			$this->verboseLog("->WriteNew");
			$req = 'writeNew';
		} else {
			$NasDistributionDataset = file_get_contents($this->config['localDataFile']);//Get data
			$this->NasDistributionDatasetFull = json_decode($NasDistributionDataset, true);//Store the full dataset
		}

		if ($req == 'readLatest') {//Grab the most recent dataset
			$key = array_key_first($this->NasDistributionDatasetFull);
			//$this->NasDistributionDatasetLatest = [$key => $this->NasDistributionDatasetFull[$key]];
			//$this->NasDistributionDatasetLatest[$key] = $this->NasDistributionDatasetLatest;
			$this->NasDistributionDatasetLatest = $this->NasDistributionDatasetFull[$key];
			$this->lastNasDistributionTimestamp = $this->NasDistributionDatasetLatest ['lastNasDistributionTimestamp'];
		} else {//write
			$this->verboseLog("->Writing data");
			$time = time();

			$NasDistributionDatasetLatest[$time] =
				['dataset'               => [
					'blockHeight'                     => $this->nodeBlockHeight,
					'nodeSyncStatus'                  => $this->nodeSynchronized,
					'coinbaseAddressNasBalance'       => $this->coinbaseAddressNasBalance,
					'distributionAddressNasBalance'   => $this->distributionAddressNasBalance,
					'personalAddressAmountToSend'     => $this->personalAddressAmountToSend,
					'totalEntitledNasForAll'          => $this->totalEntitledNasForAll,
					'lastNasDistributionTimestamp'    => time(),
					'processTransactions'             => $this->processTransactions,
					'numberOfAddressesVoting'         => $this->numberOfAddressesVoting,
					'totalTxFees'                     => $this->totalTxFees,
					'totalNAXStakedToNode'            => $this->totalNAXStakedToNode,
					'severityMessageMax'              => $this->severityMessageMax,
					'coinbaseAddress'                 => $this->config['coinbaseAddress'],
					'distributionAddress'             => $this->config['distributionAddress'],
					'personalAddress'                 => $this->config['personalAddress'],
					'distributionRatio'               => $this->config['distributionRatio'],
					'distributionAddressRemainingNas' => $this->distributionAddressRemainingNas,
					'executionStartTime'              => $this->executionStartTime,
					'executionEndTime'                => $this->executionEndTime,
					'executionTime'                   => $this->executionTimeDuration,
					'messages'                        => $this->messages,
					'sendFundsToPersonalAddress'      => $this->processStorage['sendFundsToPersonalAddress'],
					'sendFundsToDistributionAddress'  => $this->processStorage['sendFundsToDistributionAddress']],
				 'voterDataTransactions' => $this->voterData];
			$this->NasDistributionDatasetLatest = $NasDistributionDatasetLatest[$time];
			if ($req == 'writeNew') {
				$this->tempEraseMeTime = $time;
				$this->verboseLog("writeNew");
				$NasDistributionDatasetLatest[$time]['dataset']['lastNasDistributionTimestamp'] = $NasDistributionDatasetLatest[$time]['dataset']['lastNasDistributionTimestamp'] - 86400;
				$NasDistributionDatasetLatest[$time] += ['initialDataset' => $time];
				file_put_contents($this->config['localDataFile'], json_encode($NasDistributionDatasetLatest)); //Store the log
			} else {//See if the log is full before updating
				foreach ($NasDistributionDatasetLatest as $this->executionStartTime => $data) {//Remove initial dataset
					if ($data['dataset']['initialDataset'])
						unset($this->NasDistributionDatasetFull[$this->executionStartTime]);
				}
				$this->NasDistributionDatasetLatest = $NasDistributionDatasetLatest;
				$NewLog = $NasDistributionDatasetLatest + $this->NasDistributionDatasetFull;
				$cnt = count($NewLog);
				if ($cnt >= $this->datasetsToStoreLocally) { //See if the array has more inputs then specified in the config
					unset($NewLog[array_key_last($NewLog)]);
				}
				file_put_contents($this->config['localDataFile'], json_encode($NewLog), FILE_APPEND); //Store the log}
			}
			if ($req == 'writeNew')//Created the log file - now grab the data
				$this->localDataStorage();
		}
	}

	private function transferNasFromCoinbaseAddress()//Transfer mined funds to personal address and to voters
	{
		$this->verboseLog("Entered transferNasFromCoinbaseAddress()");
		$data = "{\"address\":\"$this->config['coinbaseAddress']\"}";
		$getAddressBalanceNonce = $this->getAddressBalanceNonce($this->config['coinbaseAddress']);
		if ($getAddressBalanceNonce['status'] == 'success') {
			$this->coinbaseAddressNasBalance = $getAddressBalanceNonce['balance'];
			$this->coinbaseAddressNonce = $getAddressBalanceNonce['nonce'];
		}
		//Calculate how much to transfer to the personal storage address and distributionAddress
		$movableBalance = bcsub($this->coinbaseAddressNasBalance, $this->config['amountToLeaveInCoinbaseAddress']);
		$this->distributionAddressAmountToSend = bcmul($movableBalance, $this->config['distributionRatio']);
		$this->personalAddressAmountToSend = bcsub($movableBalance, $this->distributionAddressAmountToSend);
		$this->verboseLog("Amount of NAS to send to distribution address: {$this->distributionAddressAmountToSend}\nAmount of NAS to send to private address: {$this->personalAddressAmountToSend}");
		//If no error, continue to submit the transactions
		$this->verboseLog("Current severity level: {$this->severityMessageMax}");
		if ($this->severityMessageMax < 4) {//4 is error level - do first transaction
			if ($this->personalAddressAmountToSend > $this->config['transactionFee']) {
				$this->verboseLog("Current severity level: {$this->severityMessageMax}. Sending funds to personal address.");
				$processTransaction = $this->processNasTransfer($this->config['coinbaseAddress'], $this->config['personalAddress'], $this->config['coinbaseAddressPassword'], $this->personalAddressAmountToSend, $this->coinbaseAddressNonce++);
				$this->processStorage['sendFundsToPersonalAddress'] = $processTransaction;
			} else {
				$this->storeMessages('transferNasFromCoinbaseAddress()', 'The amount of NAS to transfer to personal address does not exceed the transaction fee. Ignoring.', 'info');
			}
		}
		if ($this->severityMessageMax < 4) {//4 is error level - do second transaction
			$this->verboseLog("Current severity level: {$this->severityMessageMax}. Sending funds to distribution address.");
			$processTransaction = $this->processNasTransfer($this->config['coinbaseAddress'], $this->config['distributionAddress'], $this->config['coinbaseAddressPassword'], $this->distributionAddressAmountToSend, $this->coinbaseAddressNonce++);
			$this->processStorage['sendFundsToDistributionAddress'] = $processTransaction;
		}
	}

	function processNasTransfer($fromAddress, $toAddress, $keystorePassphrase, $value, $nonce)//Send NAS transactions
	{
		$data = "{'transaction':{'from':'{$fromAddress}','to':'{$toAddress}', 'value':'{$value}','nonce':{$nonce},'gasPrice':'1000000','gasLimit':'2000000'},'passphrase':'{$keystorePassphrase}'};";
		//Check to see if any error has occurred and if so, stop any additional transactions.
		if ($this->severityMessageMax < 4) {
			if ($this->processTransactions == true) {//We are live
				$curlRequest = $this->curlRequest('https://localhost/v1/admin/transactionWithPassphrase', $data, $timeout = 15);
				if ($curlRequest['status'] == 'success') {
					return $curlRequest;
				} else {
					$errorCurl = $curlRequest['error'];
					$this->storeMessages('curlRequest', "There was a problem submitting a transaction. To address: $toAddress, From address:$fromAddress , Transfer value: $value, Nonce: $nonce. \nError: $errorCurl", 'error');
				}
			} else {
				$this->verboseLog("Test mode - tx data to be sent: $data");
			}
		} else {
			$this->processStorage['processNasTransfer'] = "Encountered a error at some point during the process. Stopping all transactions. This TX Data: $data";
			$this->verboseLog("Encountered a error at some point during the process. Stopping all transactions. This TX Data: $data", 'error');
		}
		return null;
	}


	function getAddressBalanceNonce($address)//Get the balance and nonce from a address
	{
		$data = "{\"address\":\"$address\"}";
		$curlRequest = $this->curlRequest('https://mainnet.nebulas.io/v1/user/accountstate', $data, $timeout = 15);
		$this->verboseLog($curlRequest);

		if ($curlRequest['status'] == 'success') {
			$dataArray = json_decode($curlRequest['data'], true);
			$balance = $dataArray['result']['balance'];
			$nonce = $dataArray['result']['nonce'];
		} else {
			$errorCurl = $curlRequest['error'];
			$this->storeMessages('curlRequest', "There was a problem getting account balance for address $address. Error: $errorCurl", 'error');
		}
		$res = array('address' => $address, 'balance' => $balance, 'nonce' => $nonce,
		             'status'  => $curlRequest['status']);
		$this->processStorage['getAccountBalance'][$address] = $res;
		return $res;
	}

	private function NasBalanceInDistributionAddress()//Get the balance of the distribution address
	{//$payFromAddress
		$getAddressBalanceNonce = $this->getAddressBalanceNonce($this->config['distributionAddress']);
		if ($getAddressBalanceNonce['status'] == 'success') {
			$this->distributionAddressNasBalance = $getAddressBalanceNonce['balance'];
			$this->distributionAddressNonce = $getAddressBalanceNonce['nonce'];
		}
	}

	function calculateTotalTxFees_NasToShare()
	{
		$this->verboseLog("Entered calculateTotalTxFees_NasToShare()");
		$this->totalTxFees = bcmul($this->numberOfAddressesVoting, $this->config['transactionFee']);
		$this->NasToShare = bcsub($this->distributionAddressNasBalance, $this->totalTxFees);
	}

	private function viewInfo()
	{
		$NasBalanceR = $this->setNumDecimalPoints($this->distributionAddressNasBalance, 'makeDec', '18');
		$NasToShareR = $this->setNumDecimalPoints($this->NasToShare, 'makeDec', '18');
		$totalNAXR = $this->setNumDecimalPoints($this->totalNAXStakedToNode, 'makeDec', '9');
		$res = "\n--Data results:\nnumberOfAddressesVoting: $this->numberOfAddressesVoting\ntotalTxFees: $this->totalTxFees\nNasBalance: $this->distributionAddressNasBalance\nNasToShare:$this->NasToShare\ntotalNax: $this->totalNAXStakedToNode\npayFromAddress: $this->config['distributionAddress']\nprocessTransactions: $this->processTransactions\ntransactionFee: $this->config['transactionFee']\n
--Data results converted to readable format:\nNasBalance: $NasBalanceR\nNasToShare: $NasToShareR\ntotalNax: $totalNAXR\n";
		$this->storeMessages('viewInfo()', $res, 'info');
	}

	private function setNumDecimalPoints($val, $mode = 'dec', $decPoints = 6)
	{//Makes numbers more readable but serves no purpose for the actual process.
		if ($mode == 'dec')
			$val = number_format($val, $decPoints, '.', '');
		else if ($mode == 'noDec') {
			$val = number_format($val, $decPoints, '', '');
		} else if ($mode == 'makeDec') {
			$val = substr_replace($val, '.', -$decPoints, 0);
		}
		return $val;
	}

	private function calculateNasPerAddress()
	{
		foreach ($this->voterData as $voterDataAddress => $voterDatum) {
			//Calculate how much NAS this node is entitled to
			$percentageOverallNax = number_format(($voterDatum['votedNax'] / $this->totalNAXStakedToNode) * 100, 6);
			$entitledNas = bcmul($this->NasToShare, $percentageOverallNax);
			$entitledNas = bcdiv($entitledNas, 100, 0);
			$this->totalEntitledNasForAll = bcadd($this->totalEntitledNasForAll, $entitledNas);
			if ($entitledNas > $this->config['transactionFee']) {
				$this->voterData[$voterDataAddress] += ['percentageOverallNax' => $percentageOverallNax,
				                                        'entitledNas'          => $entitledNas];
			} else {
				$this->voterData[$voterDataAddress] += ['percentageOverallNax' => $percentageOverallNax,
				                                        'entitledNas'          => '0'];
			}
			unset($entitledNas, $percentageOverallNax);
		}
		$this->verboseLog($this->voterData);
	}

	private function getVoterData()//Get a list of voters
	{
//getNodeVoteStatistic (nodeId)
		//n214bLrE3nREcpRewHXF7qRDWCcaxRSiUdw
		//> curl -i -H 'Accept: application/json' -X POST http://mainnet.nebulas.io/v1/user/call -H 'Content-Type: application/json' -d '{"from":"n1Miq7s9MdsCXPYyTLCFjbuJ7KPgF7A3d2D","to":"n214bLrE3nREcpRewHXF7qRDWCcaxRSiUdw","value":"0","nonce":3,"gasPrice":"20000000000","gasLimit":"2000000","contract":{"function":"getNodeVoteStatistic","args":"Natoshi1"}}
		//
		/*$data = ["from"     => "n1Miq7s9MdsCXPYyTLCFjbuJ7KPgF7A3d2D", "to" => "n214bLrE3nREcpRewHXF7qRDWCcaxRSiUdw",
				 "value"    => "0", "nonce" => 3, "gasPrice" => "20000000000", "gasLimit" => "2000000",
				 "contract" => ["function" => "getNodeVoteStatistic", "args" => ['"Natoshi1"']]];*/

		$data = '{"from":"n1Miq7s9MdsCXPYyTLCFjbuJ7KPgF7A3d2D","to":"n214bLrE3nREcpRewHXF7qRDWCcaxRSiUdw","value":"0","nonce":1,"gasPrice":"1000000000000","gasLimit":"200000","contract":{"function":"getNodeVoteStatistic","args":"[\"Natoshi1\"]"}}';
		$curlRequest = $this->curlRequest('https://mainnet.nebulas.io/v1/user/call', $data, $timeout = 15);
//set the data to an array
		if ($curlRequest['status'] == 'success') {
//Requires multiple trips through the array to get the data we want (for some reason?)
			$dataResult = json_decode($curlRequest['data'], true);
			$dataResult = $dataResult['result']['result'];
			$dataResult = json_decode($dataResult, true);

			foreach ($dataResult as $thisAddressData) {
				$this->numberOfAddressesVoting++;
				$this->voterData[$thisAddressData['address']] = ['votedNax' => $thisAddressData['value']];
				$this->totalNAXStakedToNode += $thisAddressData['value'];
			}
			//$this->verboseLog("Total NAX: {$this->totalNAX}");
			//$this->verboseLog($this->voterData);
			$this->storeMessages('getVoterData()', "Total NAX: {$this->totalNAXStakedToNode}", 'info');
			$this->storeMessages('getVoterData()', "$this->voterData", 'info');
		}
	}

	private function curlRequest($url, $req = null, $timeout = 15)
	{//Standard curl call (GET default)
		$this->verboseLog("Entered curlRequest() ->$req");
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HEADER, false);
		curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Content-Type: application/json',
			'Content-Length: ' . strlen($req)));
		if ($req != null) {
			$this->verboseLog("Curl Post Fields: $req");
			//	$curlOptions += [CURLOPT_POSTFIELDS => $req, CURLOPT_POST => true,];
			curl_setopt($ch, CURLOPT_POSTFIELDS, $req);
			curl_setopt($ch, CURLOPT_POST, true);
		}
		//curl_setopt_array($ch, $curlOptions);
		$data = curl_exec($ch);
		$errors = curl_error($ch);
		$response = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		if (curl_errno($ch)) {
			$this->storeMessages('curlRequest()', 'Curl request failed. URL: ' . $url, 'error');
		} else {//Successful response
			$status = 'success';
			$this->storeMessages('curlRequest()', 'Curl request succeeded. URL: ' . $url, 'info');
		}
		curl_close($ch);//close curl
		return ['status' => $status,
		        'data'   => $data, 'error' => $errors];
	}

	private function distributeNasToVotersAddresses()
	{
		$this->verboseLog("Entered distributeNasToVotersAddresses()");
		if ($this->totalEntitledNasForAll > $this->distributionAddressNasBalance) {//Error
			$this->verboseLog("The total amount of NAS to send exceeds balance. Stopping\n {$this->totalEntitledNasForAll} > {$this->distributionAddressNasBalance}", 'error');
		} else {//Good to go
			$this->verboseLog("Entering sendout");
			foreach ($this->voterData as $voterDataAddress => $datum) {
				if ($datum['entitledNas'] > 0) {//staked enough NAX to receive NAS
					if ($this->processTransactions == true) {//We are live
						$transferResult = $this->processNasTransfer($this->config['distributionAddress'], $voterDataAddress, $this->config['distributionAddressPassword'], $datum['entitledNas'], $this->distributionAddressNonce++);
						if ($transferResult['status'] == 'success') {//Errors are handled in the processNasTransfer() function
							$resultData = json_decode($transferResult['data'], true);
							$this->voterData[$voterDataAddress] += ['txid' => $resultData['result']['hash']];
						}
					}
				}
			}
		}
	}

	private function nodeStatusRPCCheck() //Check the node status via CURL request.
	{
		$this->verboseLog("Entered nodeStatusRPCCheck()");
		$nodeStatusArray = [];
		$curlRequest = $this->curlRequest("http://localhost:8685/v1/user/nebstate");
		if ($curlRequest['status'] == 'success') {
			$nodeStatusArray = json_decode($curlRequest['data'], true);
			print_r($nodeStatusArray);
			$this->nodeStatusRPC = 'online';
		}
		if (json_last_error() == JSON_ERROR_NONE && $this->nodeStatusRPC == 'online') { //Node is online - lets check the status
			$this->nodeSynchronized = $nodeStatusArray['result']['synchronized'];
			$this->nodeBlockHeight = $nodeStatusArray['result']['height'];
			$this->nodeStatusRPC = 'online';
			$this->storeMessages(' nodeStatusRPCCheck()', "Node Online. Block height: {$nodeStatusArray['result']['height']}", 'info');
			if ($this->nodeSynchronized != true) { //Check the status file for the last recorded status
				$this->storeMessages(' nodeStatusRPCCheck()', 'Node not synchronized', 'warn');
			}
		} else { //No response from node - node is considered offline
			$this->storeMessages(' nodeStatusRPCCheck()', 'Node offline', 'error');
		}
		return null;//Results stored in pre-defined variables
	}

	private function verboseLog($val, $severity = 'info')
	{//Primarily used for debugging - can be disabled in the config
		$severityId = array_search($severity, $this->severityMessageArray);
		if ($severityId > $this->severityMessageMax)
			$this->severityMessageMax = $severityId;
		if (is_array($val))
			$val = print_r($val, true);
		$now = date("m j, Y, H:i:s");
		if ($this->config['logOutputType'] != false) {
			$logEntry = $now . ' | ' . $this->logEchoNumber . ' | ' . $val . "\n";
			$this->logEchoNumber++;
			if ($this->config['logOutputType'] == 'echo') {
				echo $logEntry;
			} else {//Write to log
				file_put_contents($this->config['logName'], $logEntry, FILE_APPEND);
			}
		}
	}

	private function storeMessages($function, $message, $severity, $verbose = true)
	{//Cleaner way to store messages
		//$this->storeMessages($function, $message, $severity);
		$severityId = array_search($severity, $this->severityMessageArray);
		if ($severityId > $this->severityMessageMax)
			$this->severityMessageMax = $severityId;

		$this->messages[] = [
			'function'    => $function,
			'messageRead' => $message,
			'result'      => $severity,
			'time'        => time()
		];
		if ($verbose)
			$this->verboseLog($message, $severity);
	}

	private function emailNotifier($req = null)
	{
		/*    //Future feature
		  if ($this->NSMSettings['reportTo == 'externalWebsite') {

				}*/
		if ($this->config['reportToEmail']) {
			//Default email service
			$to = $this->config['reportToEmail'];
			$subject = 'Nebulas profit distribution notification for ' . $this->config['nodeName'];
			$logMessage = print_r($this->config, true);
			if ($req == 'testEmail') {
				//Send a test email
				$message = 'Hello this is a requested test message from Nebulas node ' . $this->config['nodeName'] . ' with the latest log included with readable messages plus the full array result: 
			
			';
			} else if ($req == 'error') {
				$msg = $this->messages;
				$message = 'Hello this is a message from Nebulas node ' . $this->config['nodeName'] . '. It experienced a error with profit distribution and may require your attention. Below are the results: .
            
            ' . $msg;
			} else {
				$msg = json_encode($this->NasDistributionDatasetLatest, true);
				$message = 'Hello this is a message from Nebulas node ' . $this->config['nodeName'] . '. The node just completed its profit distribution process and the results are below (in JSON format).
            
            ' . $msg;
			}
			$headers = array(
				'From'     => $this->config['reportEmailFrom'],
				'Reply-To' => $this->config['reportEmailFrom'],
				'X-Mailer' => 'PHP/' . phpversion()
			);
			mail($to, $subject,
				$message,
				$headers);
		}
	}

	function __construct($config)
	{
		//Set the config
		$this->config = $config;
	}

	/*	function getLastTransfer()
		{

		} */
	/*function transferNasToDistributionAddress()//Move NAS from the coinbase address to the distribution address and private address
	{
		//Get coinbase balance
		$data = "{\"address\":\"$this->coinbaseAddress\"}";
		$curlRequest = $this->curlRequest('https://mainnet.nebulas.io/v1/user/accountstate', $data, $timeout = 15);
		$this->verboseLog($curlRequest);

		$data = "{'transaction':{'from':'{$this->distributionAddress}','to':'{$voterDataAddress}', 'value':'{$datum['entitledNas']}','nonce':{$nonce},'gasPrice':'1000000','gasLimit':'2000000'},'passphrase':'{$this->walletPassphrase}'};";
		if ($this->processTransactions == true) {//We are live
			$curlRequest = $this->curlRequest('https://localhost/v1/admin/transactionWithPassphrase', $data, $timeout = 15);
		}
	}*/

}