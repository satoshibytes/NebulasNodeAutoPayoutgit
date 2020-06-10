<?php
$config = [];
$config['logOutputType'] = 'echo';//Log output type - can be set to echo or log. echo is useful for debugging
$config['logName'] = 'nodeProfitDistribution.log';

$config['localDataFile'] = 'nodeProfitDistribution.data';


//Coinbase address info - need to have the keystore loaded on the server.
$config['coinbaseAddress'] = '';
$config['coinbaseAddressPassword'] = '';


//Distribution address info - need to have the keystore loaded on the server.
$config['distributionAddress'] = '';//This is the address that the funds are held in to distribute to community supporters - test address at the moment.
$config['distributionAddressPassword'] = '';

$config['personalAddress'] = '';

//Distribution details
$config['distributionRatio'] = 0.70;//Percentage of distribution ratio - 0.70 would mean to move 70 percent to the distribution address and 30 percent to the personal storage
$config['amountToLeaveInCoinbaseAddress'] = 1000000000000000000;
$config['transactionFee'] = 10000000000000;// 0.00001;//The transaction fee to withdraw
$config['hoursBetweenRuns'] = 24;//Set how often in hours to distribute NAS - setup a cron job 0 * * * * php /path/nodeProfitDistribution.php var
$config['delayBetweenDistributions'] = 16;//Number of seconds to wait for each transaction


//Data export - nebulas.pro
$config['exportDatasetToUrl'] = true;
$config['exportDatasetURL'] = 'https://nebulas.pro/api/api.php';
$config['exportDatasetPrivateKey'] = '';//The data is transmitted via ssl encryption
$config['exportDatasetUsername'] = '';

//Node email service
$config['nodeName'] = '';
$config['reportToEmail'] = '';
if (file_exists('privateConfig.php')) {//Set the private config files without leaking them to the repo.
	include_once('privateConfig.php');
}