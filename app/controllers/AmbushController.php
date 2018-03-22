AmbushController.php
<?php

class AmbushController extends Zend_Controller_Action
{

     public function init()
    {

     

        $this->_helper->layout->setLayoutPath(APPLICATION_PATH . '/layouts/scripts/admin/');
    }
    


    
    public function indexAction()
    {

    }
    
    public function ipLogAction(){
        $iplog_table = new Application_Model_DbTable_x2payIpLog();
        
        $this->view->iplog = $iplog_table->getAllIpLog();
        $this->view->headers = array_keys($this->view->iplog[0]);
    }
    
    public function memberProfileAction(){
        $member_table = new Application_Model_DbTable_X2payUsers();
        $this->view->members = $member_table->getAllBriefInfo();
        $this->view->headers = array_keys($this->view->members[0]);
    }
    
    public function insertMemberProfileAction(){
        $this->view->member_profile = @new Application_Form_MemberProfile(null, true);
        
        if($this->getRequest()->isPost() && $this->view->member_profile->isValid($this->getRequest()->getPost())){
            $values = $this->view->member_profile->getValues();
            
            $users_table = new Application_Model_DbTable_X2payUsers();
            $users_table->insertUser($values);

            $this->redirect($this->view->Url(array('controller'=>'ambush','action'=>'member-profile')));
        }
    }
    
    public function editMemberProfileAction(){
        /*TO DO MOVE THIS TO ONE FUNCTON*/
        $userid = $this->getRequest()->getParam('id');
        
        $users_table = new Application_Model_DbTable_X2payUsers();
        $alldata = $users_table->getUserAllInfo($userid);
        
        $this->view->member_profile = new Application_Form_MemberProfile($alldata, true);
        if($this->getRequest()->isPost() && $this->view->member_profile->isValid($this->getRequest()->getPost())){
            
            $values = $this->view->member_profile->getValues();
            $users_table = new Application_Model_DbTable_X2payUsers();
            $users_table->updateUser($values, $userid);
            
            $this->redirect($this->view->Url(array('controller'=>'ambush','action'=>'member-profile')));

        }
    }
    
    public function deleteMemberProfileAction(){
        $userid = $this->getRequest()->getParam('id');
        
        $users_table = new Application_Model_DbTable_X2payUsers();
        $users_table->delete("id=$userid");
        
        $this->redirect($this->view->Url(array('controller'=>'ambush','action'=>'member-profile')));
    }
    
    public function accountStatementAction(){
        $transaction_table = new Application_Model_DbTable_x2payTransactions();
        $curency_table = new Application_Model_DbTable_X2payCurrencies();
        
        $this->view->transactions = $transaction_table->getPendingAccountStatement();
        $this->view->currencies = $curency_table->getAllCurrencies();
        
        if($this->view->transactions){
            $this->view->headers = array_keys($this->view->transactions[0]);
        }
    }
    
    public function transactionsAction(){
        $company_id = intval($this->getRequest()->getParam('company'));
        
        $transaction_table = new Application_Model_DbTable_x2payTransactions();
        
        $this->view->transactions = $transaction_table->getAccountStatement($company_id, '1970-01-01', '2099-12-31', null);
        
        if($this->view->transactions){
            $this->view->headers = array_keys($this->view->transactions[0]);
        }
        
        $this->view->filter = new Application_Form_TransactionFilter($company_id);
    }
    
    public function markCompletedAction(){
        $id = intval($this->getRequest()->getParam('id'));
        $amount = $this->getRequest()->getParam('amount');
        
        //AMOUNT IS BANK FEE, ADMIN CAN FILL THIS AMOUNT AND DEDUCT TO TOTAL BALANCE
        if($amount){
            intval($amount);
        }else{
            $amount = 0;
        }
        
        $db = Zend_Db_Table::getDefaultAdapter();
        $db->beginTransaction();
        
        try{
            $transaction_table = new Application_Model_DbTable_x2payTransactions();
            $transaction = $transaction_table->fetchRow("id=$id");
            $payer_id = $transaction['payer_id'];
            
            $type = ($payer_id == 1) ? 'Deposit' : 'Withdrawal';
            
            $values = array(
                'status'=>'Completed',
                'create_date'=>date("Y-m-d H:i:s"),
                );
                 
            if($type == 'Deposit'){
                $values['amount_paid'] = $amount;
            }
            
            $transaction_table->update($values, "id=$id");
            
            //UPDATE USER BALANCE IF IT IS DEPOSIT
            $transaction = $transaction_table->fetchRow("id=$id");
            $payer_id = $transaction['payer_id'];

            if($type == 'Deposit'){
                $balance_table = new Application_Model_DbTable_X2payUsersBalance();
                $current_amount = $balance_table->fetchRow("userid={$transaction['payee_id']} AND currencyid={$transaction['currency_id']}");
                $balance_table->update(array(
                    'amount'=>$current_amount['amount'] + $transaction['amount_paid']), 
                    "userid={$transaction['payee_id']} AND currencyid={$transaction['currency_id']}");
            
                $transaction_table->update(array(
                    'payee_total_amount'=>$current_amount['amount'] + $transaction['amount_paid'],
                    ), "id=$id");    
            } else {
                $balance_table = new Application_Model_DbTable_X2payUsersBalance();
                $current_amount = $balance_table->fetchRow("userid={$transaction['payer_id']} AND currencyid={$transaction['currency_id']}");
                $balance_table->update(array(
                    'amount'=>$current_amount['amount'] - $transaction['amount_paid']), 
                    "userid={$transaction['payer_id']} AND currencyid={$transaction['currency_id']}");
            }
            
            $db->commit();
            
        } catch (Exception $e) {
            $db->rollback();

            throw $e; 
        }
        
        $member_table = new Application_Model_DbTable_X2payUsers();
        if($type == 'Withdrawal'){
            $email = $member_table->getUserEmailById($transaction['payer_id']);
        } else {
            $email = $member_table->getUserEmailById($transaction['payee_id']);
        }
        
        if($email){
            //SEND NOTIFICATION EMAIL
            $mail = new Zend_Mail();
            $mail->addTo($email);
            
            if($type == 'Withdrawal'){
                $mail->setSubject('Result of Fund Withdrawal (Success)');
                ob_start();
                include APPLICATION_PATH . '/views/scripts/_email_complete_withdrawal.phtml';
            }else{
                $mail->setSubject('Result of Fund Deposit (Success)');
                ob_start();
                include APPLICATION_PATH . '/views/scripts/_email_complete_deposit.phtml';
            }
            $message = ob_get_clean();

            $mail->setBodyText($message);
            $mail->setFrom('cs@x2payonline.com');
            $mail->send();
        }
        
        $this->redirect($this->view->Url(array('controller'=>'ambush','action'=>'account-statement'), null, true));
    }
    
    public function changeUserStatusAction(){
        $code = intval($this->getRequest()->getParam('to'));
        $id = intval($this->getRequest()->getParam('id'));
        
        $users_table = new Application_Model_DbTable_X2payUsers();
        $users_table->update(
                array('status'=>$code), 
                "id=$id");
        
        $this->redirect($this->view->Url(array('controller'=>'ambush','action'=>'member-profile')));
    }
    
    public function voidTransactionAction(){
        $id = intval($this->getRequest()->getParam('id'));

        $transaction_table = new Application_Model_DbTable_x2payTransactions();
        $transaction = $transaction_table->fetchRow("id=$id");
        $transaction_table->delete("id=$id");
        
        $member_table = new Application_Model_DbTable_X2payUsers();

        $this->redirect($this->view->Url(array('controller'=>'ambush','action'=>'account-statement'), null, true));
    }
    
   public function searchAction()
    {
        
        
        
        $this->view->action = 'search';
        
        $this->view->search  = new Application_Form_Search();
        if($this->getRequest()->isPost() && $this->view->search->isValid($this->getRequest()->getPost())){
            $values = $this->view->search->getValues();
            
            $transaction_table = new Application_Model_DbTable_x2payTransactions();
            $transactions = $transaction_table->getTransctionInfo($this->user->id, $values['search_by'], $values['search_input']);
            
            if($transactions){
                $this->view->transactions = $transactions;
                $this->view->headers = array_keys($this->view->transactions[0]);
                
            $transaction_table = new Application_Model_DbTable_x2payInvoiceDate();
            $invoice_date = $transaction_table->getInvoiceDateInfo($this->invoice->createdAt,$values['search_by'], $values['search_input']);
            
            if($invoice_date){
                $this->view->transaction = $transactions;
                $this->view->headers = array_keys($this->view->transactions[0]);
             }
            }
        }

    }
         
        
    
    
    public function logoutAction(){
        
    }
    
}
