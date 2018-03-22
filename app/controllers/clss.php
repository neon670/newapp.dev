/home/shaneh45/public_html/x2payonline.com/application/models/DbTable/x2payTransactions.php

<?php

class Application_Model_DbTable_x2payTransactions extends Zend_Db_Table_Abstract
{
    protected $_name = 'x2pay_transactions';
    protected $_primary = 'id';

    public function getTransctionInfo($userid, $searchby, $value){
        $select = $this->select()->setIntegrityCheck(false)->from('x2pay_transactions', array(
            'Transaction ID' => 'LPAD(x2pay_transactions.id, 7, 0)', 
            'Invoice Number' => 'invoice_no', 
            'Date' => 'create_date', 
            'Amount' => 'amount_paid'))
                ->joinLeft(array('x2pay_currencies'), 'x2pay_transactions.currency_id = x2pay_currencies.id', array('Currency'=>'currency_name'))
                ->joinLeft(array('payer'=>'x2pay_users'), 'payer.id = x2pay_transactions.payer_id', array('Payer'=>'company_name'))
                ->joinLeft(array('payee'=>'x2pay_users'), 'payee.id = x2pay_transactions.payee_id', array('Payee'=>'company_name'))
                ->where("payer_id=?", array($userid));

        $search_map = array('1'=>'x2pay_transactions.id',
                        '2'=>'invoice_no',
                        '3'=>'payee.company_name',
                        '4'=>'payer.company_name');
        
        $search_map = $search_map[$searchby];
        if($searchby == '1'){
            $select->where("$search_map = ?", $value);
        } else{
            $select->where("$search_map LIKE ?", "%$value%");
        }
        
        $transaction  = $this->fetchAll($select)->toArray();
        
        return $transaction;
    }
    
    public function hasPendingWithdrawal($userid){
        return !is_null($this->fetchRow("payer_id = $userid AND status='Pending'"));
    }
    
    public function getPendingAccountStatement(){
        $result = $this->getAccountStatement(1, '1970-01-01', '2099-12-31', null, 'Pending');
        
        foreach($result as &$r){
            if($r['Description'] === 'Withdrawal') {
                $r['Company'] = $r['Payer'];
            } else if($r['Description'] === 'Deposit'){
                $r['Company'] = $r['Beneficiary'];
            }
            
            unset($r['Balance Total']);
            unset($r['Invoice Number']);
            unset($r['Amount Total']);
            unset($r['Beneficiary']);
            unset($r['Payer']);
        }
        
        return $result;
    }
    
    public function getAccountStatement($userid, $from_date, $to_date, $currency=null, $status='Completed'){
        
        $select1 = $this->select()->setIntegrityCheck(false)->from('x2pay_transactions', array(
            'X2_Pay_Transaction' => 'LPAD(x2pay_transactions.id, 7, 0)', 
            'Invoice Number' => 'invoice_no', 
            'Date' => 'create_date', 
            'Currency'=>'x2pay_currencies.currency_name',
            'Amount' => 'ROUND(amount_paid, 2)',
            'Balance Total'=>'ROUND(payer_total_amount, 2)'))
                
                ->joinLeft(array('payer'=>'x2pay_users'), 'payer.id = x2pay_transactions.payer_id', array('payer_id'=>'payer.id', 'payee_id'=>'payee.id', 'Payer'=>'company_name'))
                ->joinLeft(array('x2pay_currencies'), 'x2pay_transactions.currency_id = x2pay_currencies.id', array('Currency'=>'currency_name'));
          
        $where1 = "x2pay_transactions.status = '$status' AND (create_date BETWEEN '$from_date' AND ADDDATE('$to_date', 1))";
        if($userid){
            $where1 = "(payer_id = $userid) AND " . $where1;
        }
        $select1->joinLeft(array('payee'=>'x2pay_users'), 'payee.id = x2pay_transactions.payee_id', array('Beneficiary'=>'company_name'))
                ->where($where1);
        
        if($currency){
            $select1->where("currency_id = $currency");
        }
        
        $select2 = $this->select()->setIntegrityCheck(false)->from('x2pay_transactions', array(
            'X2 Pay Transaction' => 'LPAD(x2pay_transactions.id, 7, 0)', 
            'Invoice Number' => 'invoice_no', 
            'Date' => 'create_date', 
            'Currency'=>'x2pay_currencies.currency_name',
            'Amount' => 'ROUND(amount_paid, 2)',
            'Balance Total'=>'ROUND(payee_total_amount, 2)'))
                ->joinLeft(array('payer'=>'x2pay_users'), 'payer.id = x2pay_transactions.payer_id', array('payer_id'=>'payer.id', 'payee_id'=>'payee.id', 'Payer'=>'company_name'))
                ->joinLeft(array('x2pay_currencies'), 'x2pay_transactions.currency_id = x2pay_currencies.id', array('Currency'=>'currency_name'));
            
        $where2 = "x2pay_transactions.status = '$status' AND (create_date BETWEEN '$from_date' AND ADDDATE('$to_date', 1))";
        if($userid){
            $where2 = "(payee_id = $userid) AND " . $where2;
        }
        $select2->joinLeft(array('payee'=>'x2pay_users'), 'payee.id = x2pay_transactions.payee_id', array('Beneficiary'=>'company_name'))
                ->where($where2);
        
        if($currency){
            $select2->where("currency_id = $currency");
        }
        
        $select = $this->select()->union(array($select1, $select2))->order(array("Date ASC", "X2_Pay_Transaction ASC"));
        $transactions  = $this->fetchAll($select)->toArray();
        
        foreach($transactions as &$transaction){
            if($transaction['payee_id'] == 1){
                $transaction['Description'] = 'Withdrawal';
            } elseif($transaction['payer_id'] == 1){
                $transaction['Description'] = 'Deposit';
            } else {
                if($transaction['payer_id'] == $userid){
                    $transaction['Description'] = 'Payment Sent';
                } else {
                    $transaction['Description'] = 'Payment Received';
                }
            }
        
            unset($transaction['payer_id']);
            unset($transaction['payee_id']);
        }
        
        return $transactions;
    }
}
