<?php

namespace OfxParser;

use SimpleXMLElement;
use OfxParser\Entities\AccountInfo;
use OfxParser\Entities\BankAccount;
use OfxParser\Entities\Institute;
use OfxParser\Entities\SignOn;
use OfxParser\Entities\Statement;
use OfxParser\Entities\Status;
use OfxParser\Entities\Transaction;

/**
 * The OFX object
 *
 * Heavily refactored from Guillaume Bailleul's grimfor/ofxparser
 *
 * Second refactor by Oliver Lowe to unify the API across all
 * OFX data-types.
 *
 * Based on Andrew A Smith's Ruby ofx-parser
 *
 * @author Guillaume BAILLEUL <contact@guillaume-bailleul.fr>
 * @author James Titcumb <hello@jamestitcumb.com>
 * @author Oliver Lowe <mrtriangle@gmail.com>
 */
class Ofx
{
    public $Header;
    public $SignOn;
    public $SignupAccountInfo;
    public $BankAccounts = [];
    public $BankAccount;
    public $Investment;

    /**
     * @param SimpleXMLElement $xml
     */
    public function __construct(SimpleXMLElement $xml)
    {
        $this->SignOn = $this->buildSignOn($xml->SIGNONMSGSRSV1->SONRS);
        $this->SignupAccountInfo = $this->buildAccountInfo($xml->SIGNUPMSGSRSV1->ACCTINFOTRNRS);
        $this->BankAccounts = $this->buildBankAccounts($xml);
        // Set a helper if only one bank account
        if (count($this->BankAccounts) == 1) {
            $this->BankAccount = $this->BankAccounts[0];
        }
    }

    /**
     * Get the transactions that have been processed
     *
     * @return array
     */
    public function getTransactions()
    {
        return $this->BankAccount->Statement->Transactions;
    }

    /**
     * @param $xml
     * @return SignOn
     */
    private function buildSignOn($xml)
    {
        $SignOn = new SignOn();
        $SignOn->Status = $this->buildStatus($xml->STATUS);
        $SignOn->date = $this->createDateTimeFromStr($xml->DTSERVER, true);
        $SignOn->language = $xml->LANGUAGE;

        $SignOn->Institute = new Institute();
        $SignOn->Institute->name = $xml->FI->ORG;
        $SignOn->Institute->id = $xml->FI->FID;

        return $SignOn;
    }

    /**
     * @param $xml
     * @return array AccountInfo
     */
    private function buildAccountInfo($xml)
    {
        if (!isset($xml->ACCTINFO)) return [];

        $accounts = [];
        foreach ($xml->ACCTINFO as $account) {
            $AccountInfo = new AccountInfo();
            $AccountInfo->desc = $account->DESC;
            $AccountInfo->number = $account->ACCTID;
            $accounts[] = $AccountInfo;
        }

        return $accounts;
    }

    /**
     * @param SimpleXMLElement $xml
     * @return array
     */
    private function buildBankAccounts(SimpleXMLElement $xml)
    {
        // Loop through the bank accounts
        $bankAccounts = [];
        foreach ($xml->BANKMSGSRSV1->STMTTRNRS as $accountStatement) {
            $bankAccounts[] = $this->buildBankAccount($accountStatement);
        }
        return $bankAccounts;
    }

    /**
     * @param $xml
     * @return BankAccount
     * @throws \Exception
     */
    private function buildBankAccount($xml)
    {
        $Bank = new BankAccount();
        $Bank->transactionUid = $xml->TRNUID;
        $Bank->agencyNumber = $xml->STMTRS->BANKACCTFROM->BRANCHID;
        $Bank->accountNumber = $xml->STMTRS->BANKACCTFROM->ACCTID;
        $Bank->routingNumber = $xml->STMTRS->BANKACCTFROM->BANKID;
        $Bank->accountType = $xml->STMTRS->BANKACCTFROM->ACCTTYPE;
        $Bank->balance = $xml->STMTRS->LEDGERBAL->BALAMT;
        $Bank->balanceDate = $xml->STMTRS->LEDGERBAL->DTASOF ?$this->createDateTimeFromStr($xml->STMTRS->LEDGERBAL->DTASOF, true) : '';

        $Bank->Statement = new Statement();
        $Bank->Statement->currency = $xml->STMTRS->CURDEF;
        $Bank->Statement->startDate = $this->createDateTimeFromStr($xml->STMTRS->BANKTRANLIST->DTSTART);
        $Bank->Statement->endDate = $this->createDateTimeFromStr($xml->STMTRS->BANKTRANLIST->DTEND);
        $Bank->Statement->transactions = $this->buildTransactions($xml->STMTRS->BANKTRANLIST->STMTTRN);

        return $Bank;
    }

    private function buildTransactions($transactions)
    {
        $return = [];
        foreach ($transactions as $t) {
            $Transaction = new Transaction();
            $Transaction->type = (string)$t->TRNTYPE;
            $Transaction->date = $this->createDateTimeFromStr($t->DTPOSTED);
            $Transaction->amount = $this->createAmountFromStr($t->TRNAMT);
            $Transaction->uniqueId = (string)$t->FITID;
            $Transaction->name = (string)$t->NAME;
            $Transaction->memo = (string)$t->MEMO;
            $Transaction->sic = $t->SIC;
            $Transaction->checkNumber = $t->CHECKNUM;
            $return[] = $Transaction;
        }

        return $return;
    }

    private function buildStatus($xml)
    {
        $Status = new Status();
        $Status->code = $xml->CODE;
        $Status->severity = $xml->SEVERITY;
        $Status->message = $xml->MESSAGE;

        return $Status;
    }

    /**
     * Create a DateTime object from a valid OFX date format
     *
     * Supports:
     * YYYYMMDDHHMMSS.XXX[gmt offset:tz name]
     * YYYYMMDDHHMMSS.XXX
     * YYYYMMDDHHMMSS
     * YYYYMMDD
     * YYYY-MM-DD
     *
     * @param  string  $dateString
     * @param  boolean $ignoreErrors
     * @return \DateTime | $dateString
     */
    private function createDateTimeFromStr($dateString, $ignoreErrors = false)
    {
      
        $format = $this->formatDate($dateString);
        try {
            return new \DateTime($format);

        } catch (\Exception $e) {

            if ($ignoreErrors) {
                return null;
            }

            throw $e;
        }
       
    }

    /**
     * Create a formated number in Float according to different locale options
     *
     * Supports:
     * 000,00 and -000,00
     * 0.000,00 and -0.000,00
     * 0,000.00 and -0,000.00
     * 000.00 and 000.00
     *
     * @param  string  $amountString
     * @return float
     */
    private function createAmountFromStr($amountString)
    {
        //000.00 or 0,000.00
        if (preg_match("/^-?([0-9,]+)(\.?)([0-9]{2})$/", $amountString) == 1) {
            $amountString = preg_replace(
                array("/([,]+)/",
                    "/\.?([0-9]{2})$/"
                    ),
                array("",
                    ".$1"),
                $amountString);
        }

        //000,00 or 0.000,00
        elseif (preg_match("/^-?([0-9\.]+,?[0-9]{2})$/", $amountString) == 1) {
            $amountString = preg_replace(
                array("/([\.]+)/",
                    "/,?([0-9]{2})$/"
                    ),
                array("",
                    ".$1"),
                $amountString);
        }

        return (float)$amountString;
    }

    /**
     *  Format Date for create date 
     *
     * Supports:
     * YYYYMMDDHHMMSS.XXX[gmt offset:tz name]
     * YYYYMMDDHHMMSS.XXX
     * YYYYMMDDHHMMSS
     * YYYYMMDD
     * YYYY-MM-DD
     * 
     * dd/mm/aaaa
     *
     * 
     * @param string $date
     * @return string
     */
    private function formatDate($date) {
     
        $regex_format_american = "/"
        . "(\d{4})[-]?(\d{2})[-]?(\d{2})?" // YYYYMMDD   YYYY-MM-DD          1,2,3
        . "(?:(\d{2})(\d{2})(\d{2}))?" // HHMMSS   - optional  4,5,6
        . "(?:\.(\d{3}))?" // .XXX     - optional  7
        . "(?:\[(-?\d+)\:(\w{3}\]))?" // [-n:TZ]  - optional  8,9
        . "/";

        $regex_format_brazilian = "/"
        . "(\d{2})\/(\d{2})\/(\d{4})?" //    dd/mm/aaaa   1,2,3
        . "(?:(\d{2})(\d{2})(\d{2}))?" // HHMMSS   - optional  4,5,6
        . "(?:\.(\d{3}))?" // .XXX     - optional  7
        . "(?:\[(-?\d+)\:(\w{3}\]))?" // [-n:TZ]  - optional  8,9
        . "/";

        if (preg_match($regex_format_american, $date, $matches)) {
            $year = (int)$matches[1];
            $month = (int)$matches[2];
            $day = (int)$matches[3];
            $hour = isset($matches[4]) ? $matches[4] : 0;
            $min = isset($matches[5]) ? $matches[5] : 0;
            $sec = isset($matches[6]) ? $matches[6] : 0;
            $format = $year . '-' . $month . '-' . $day . ' ' . $hour . ':' . $min . ':' . $sec;
            return $format;  

        } else if (preg_match($regex_format_brazilian, $date, $matches)) {
            $day = (int)$matches[1];
            $month = (int)$matches[2];
            $year = (int)$matches[3];
            $hour = isset($matches[4]) ? $matches[4] : 0;
            $min = isset($matches[5]) ? $matches[5] : 0;
            $sec = isset($matches[6]) ? $matches[6] : 0;
            $format = $year . '-' . $month . '-' . $day . ' ' . $hour . ':' . $min . ':' . $sec;
            return $format;
        }

        throw new \Exception("Failed to initialize DateTime for string: " . $date);

    }
}
