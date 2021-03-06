<?php
namespace Oara\Network\Publisher;
    /**
     * The goal of the Open Affiliate Report Aggregator (OARA) is to develop a set
     * of PHP classes that can download affiliate reports from a number of affiliate networks, and store the data in a common format.
     *
     * Copyright (C) 2016  Fubra Limited
     * This program is free software: you can redistribute it and/or modify
     * it under the terms of the GNU Affero General Public License as published by
     * the Free Software Foundation, either version 3 of the License, or any later version.
     * This program is distributed in the hope that it will be useful,
     * but WITHOUT ANY WARRANTY; without even the implied warranty of
     * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
     * GNU Affero General Public License for more details.
     * You should have received a copy of the GNU Affero General Public License
     * along with this program.  If not, see <http://www.gnu.org/licenses/>.
     *
     * Contact
     * ------------
     * Fubra Limited <support@fubra.com> , +44 (0)1252 367 200
     **/
/**
 * Api Class
 *
 * @author     Carlos Morillo Merino
 * @category   An
 * @copyright  Fubra Limited
 * @version    Release: 01.00
 *
 */
class AffiliNet extends \Oara\Network
{
    private $_client = null;
    private $_token = null;
    private $_paymentHistory = null;
    /**
     * @param $credentials
     */
    public function login($credentials)
    {
        $user = $credentials['user'];
        $password = $credentials['apipassword'];

        //Setting the client.
        $this->_client = new \SoapClient('https://api.affili.net/V2.0/Logon.svc?wsdl', array('compression' => SOAP_COMPRESSION_ACCEPT | SOAP_COMPRESSION_GZIP | SOAP_COMPRESSION_DEFLATE, 'soap_version' => SOAP_1_1));
        $this->_token = $this->_client->Logon(array(
            'Username' => $user,
            'Password' => $password,
            'WebServiceType' => 'Publisher'
        ));
        $this->_user = $user;
    }

    /**
     * @return array
     */
    public function getNeededCredentials()
    {
        $credentials = array();

        $parameter = array();
        $parameter["description"] = "User Log in";
        $parameter["required"] = true;
        $parameter["name"] = "User";
        $credentials["user"] = $parameter;

        $parameter = array();
        $parameter["description"] = "Password to Log in";
        $parameter["required"] = true;
        $parameter["name"] = "Password";
        $credentials["password"] = $parameter;

        return $credentials;
    }

    /**
     * @return bool
     */
    public function checkConnection()
    {
        $connection = true;
        return $connection;
    }

    /**
     * @return array
     * @throws Exception
     */
    public function getMerchantList()
    {
        $merchantListResult = array();
        //Set the webservice
        $publisherProgramServiceUrl = 'https://api.affili.net/V2.0/PublisherProgram.svc?wsdl';
        $publisherProgramService = new \SoapClient($publisherProgramServiceUrl, array('compression' => SOAP_COMPRESSION_ACCEPT | SOAP_COMPRESSION_GZIP | SOAP_COMPRESSION_DEFLATE, 'soap_version' => SOAP_1_1));
        //Call the function
        $params = Array('Query' => '');
        $merchantList = self::affilinetCall('merchant', $publisherProgramService, $params);

        if ($merchantList->TotalRecords > 0) {
            if ($merchantList->TotalRecords == 1) {
                $merchant = $merchantList->Programs->ProgramSummary;
                $merchantList = array();
                $merchantList[] = $merchant;
            } else {
                $merchantList = $merchantList->Programs->ProgramSummary;
            }

            foreach ($merchantList as $merchant){
                $obj = array();
                $obj['cid'] = $merchant->ProgramId;
                $obj['name'] = $merchant->ProgramTitle;
                $obj['url'] = $merchant->Url;
                $merchantListResult[] = $obj;
            }

        } else {
            $merchantListResult = array();
        }

        return $merchantListResult;
    }

    /**
     * @param null $merchantList
     * @param \DateTime|null $dStartDate
     * @param \DateTime|null $dEndDate
     * @return array
     * @throws Exception
     */
    public function getTransactionList($merchantList = null, \DateTime $dStartDate = null, \DateTime $dEndDate = null)
    {
        $totalTransactions = array();

        $merchantIdList = \Oara\Utilities::getMerchantIdMapFromMerchantList($merchantList);

        $publisherStatisticsServiceUrl = 'https://api.affili.net/V2.0/PublisherStatistics.svc?wsdl';
        $publisherStatisticsService = new \SoapClient($publisherStatisticsServiceUrl, array('compression' => SOAP_COMPRESSION_ACCEPT | SOAP_COMPRESSION_GZIP | SOAP_COMPRESSION_DEFLATE, 'soap_version' => SOAP_1_1));
        $iterationNumber = self::calculeIterationNumber(\count($merchantIdList), 100);

        for ($currentIteration = 0; $currentIteration < $iterationNumber; $currentIteration++) {
            $merchantListSlice = \array_slice(\array_keys($merchantIdList), 100 * $currentIteration, 100);
            $merchantListAux = array();
            foreach ($merchantListSlice as $merchant) {
                $merchantListAux[] = (string)$merchant;
            }
            $params = array(
                'StartDate' => \strtotime($dStartDate->format("Y-m-d")),
                'EndDate' => \strtotime($dEndDate->format("Y-m-d")),
                'TransactionStatus' => 'All',
                'ProgramIds' => $merchantListAux
            );
            $currentPage = 1;
            $transactionList = self::affilinetCall('transaction', $publisherStatisticsService, $params, 0, $currentPage);

            while (isset($transactionList->TotalRecords) && $transactionList->TotalRecords > 0 && isset($transactionList->TransactionCollection->Transaction)) {
                $transactionCollection = array();
                if (!\is_array($transactionList->TransactionCollection->Transaction)) {
                    $transactionCollection[] = $transactionList->TransactionCollection->Transaction;
                } else {
                    $transactionCollection = $transactionList->TransactionCollection->Transaction;
                }

                foreach ($transactionCollection as $transactionObject){

                    $transaction = array();
                    $transaction["status"] = $transactionObject->TransactionStatus;
                    $transaction["unique_id"] = $transactionObject->TransactionId;
                    $transaction["commission"] = $transactionObject->PublisherCommission;
                    $transaction["amount"] = $transactionObject->NetPrice;
                    $transaction["date"] = $transactionObject->RegistrationDate;
                    $transaction["merchantId"] = $transactionObject->ProgramId;
                    $transaction["custom_id"] = $transactionObject->SubId;
                    if ($transaction['status'] == 'Confirmed') {
                        $transaction['status'] = \Oara\Utilities::STATUS_CONFIRMED;
                    } else
                        if ($transaction['status'] == 'Open') {
                            $transaction['status'] = \Oara\Utilities::STATUS_PENDING;
                        } else
                            if ($transaction['status'] == 'Cancelled') {
                                $transaction['status'] = \Oara\Utilities::STATUS_DECLINED;
                            }
                    $totalTransactions[] = $transaction;
                }
                $currentPage++;
                $transactionList = self::affilinetCall('transaction', $publisherStatisticsService, $params, 0, $currentPage);
            }
        }

        return $totalTransactions;
    }

    /**
     * @return array
     * @throws Exception
     */
    public function getPaymentHistory()
    {

        $paymentHistory = array();
        $auxStartDate = new \DateTime("2000-01-01");
        $auxStartDate->setTime(0,0);
        $auxEndDate = new \DateTime();
        $params = array(
            'CredentialToken' => $this->_token,
            'PublisherId' => $this->_user,
            'StartDate' => \strtotime($auxStartDate->format("Y-m-d")),
            'EndDate' => \strtotime($auxEndDate->format("Y-m-d")),
        );
        $accountServiceUrl = 'https://api.affili.net/V2.0/AccountService.svc?wsdl';
        $accountService = new \SoapClient($accountServiceUrl, array('compression' => SOAP_COMPRESSION_ACCEPT | SOAP_COMPRESSION_GZIP | SOAP_COMPRESSION_DEFLATE, 'soap_version' => SOAP_1_1));

        $paymentList = self::affilinetCall('payment', $accountService, $params);

        if (isset($paymentList->PaymentInformationCollection) && !\is_array($paymentList->PaymentInformationCollection)) {
            $paymentList->PaymentInformationCollection = array($paymentList->PaymentInformationCollection);
        }
        if (isset($paymentList->PaymentInformationCollection)) {
            foreach ($paymentList->PaymentInformationCollection as $payment) {
                $obj = array();
                $obj['method'] = $payment->PaymentType;
                $obj['pid'] = $payment->PaymentId;
                $obj['value'] = $payment->GrossTotal;
                $obj['date'] = $payment->PaymentDate;
                $paymentHistory[] = $obj;
            }
        }
        $this->_paymentHistory = $paymentHistory;
        return $paymentHistory;
    }

    /**
     * Call to the API controlling the exception and Login
     */
    private function affilinetCall($call, $ws, $params, $try = 0, $currentPage = 0)
    {
        $result = null;
        try {

            switch ($call) {
                case 'merchant':
                    $result = $ws->GetMyPrograms(array('CredentialToken' => $this->_token,
                        'GetProgramsRequestMessage' => $params));
                    break;
                case 'transaction':
                    $pageSettings = array("CurrentPage" => $currentPage, "PageSize" => 100);
                    $result = $ws->GetTransactions(array('CredentialToken' => $this->_token,
                        'TransactionQuery' => $params,
                        'PageSettings' => $pageSettings));
                    break;
                case 'overview':
                    $result = $ws->GetDailyStatistics(array('CredentialToken' => $this->_token,
                        'GetDailyStatisticsRequestMessage' => $params));
                    break;
                case 'payment':
                    $result = $ws->GetPayments($params);
                    break;
                default:
                    throw new \Exception('No Affilinet Call available');
                    break;
            }
        } catch (\Exception $e) {
            //checking if the token is valid
            if (\preg_match("/Login failed/", $e->getMessage()) && $try < 5) {
                self::login();
                $try++;
                $result = self::affilinetCall($call, $ws, $params, $try, $currentPage);
            } else {
                throw new \Exception("problem with Affilinet API, no login fault");
            }
        }

        return $result;

    }

    /**
     * @param $rowAvailable
     * @param $rowsReturned
     * @return int
     */
    private function calculeIterationNumber($rowAvailable, $rowsReturned)
    {
        $iterationDouble = (double)($rowAvailable / $rowsReturned);
        $iterationInt = (int)($rowAvailable / $rowsReturned);
        if ($iterationDouble > $iterationInt) {
            $iterationInt++;
        }
        return $iterationInt;
    }
}
