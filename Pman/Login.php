<?php

require_once 'Pman.php';

class Pman_Login extends Pman
{ 
    
    var $masterTemplate = 'login.html';
    
    function getAuth() // everyone allowed in here..
    {
        parent::getAuth(); // load company..
        return true;
        
    }
    /**
     * Accepts:
     * logout =
     * 
     * 
     */
    function get() 
    {
        
        
        
        
        if (!empty($_REQUEST['logout'])) {
            $u = $this->getAuthUser();
            //print_r($u);
            if ($u) {
                $this->addEvent('LOGOUT');
                $u->logout();
            }
            // log it..
            
            $_SESSION['Pman_I18N'] = array();
            
        }
        
        // general query...
        if (!empty($_REQUEST['getAuthUser'])) {
            $this->sendAuthUserDetails();
            exit;
           
        }
        if (!empty($_REQUEST['username'])) {
            $this->post()
        }
        $this->jerr("INVALID REQUEST");
        exit;
    }
    
    function sendAuthUserDetails()
    {
        $u = DB_DataObject::factory('Person');
        if (!$u->isAuth()) {
            $this->jok(array('id' => 0)); // not logged in..
            exit;
        }
        $au = $u->getAuthUser();
        $aur = $au->toArray();
        //DB_DataObject::debugLevel(1);
        $c = DB_Dataobject::factory('Companies');
        $im = DB_Dataobject::factory('Images');
        $c->joinAdd($im, 'LEFT');
        $c->selectAdd();
        $c->selectAs($c, 'company_id_%s');
        $c->selectAs($im, 'company_id_logo_id_%s');
        $c->id = $au->company_id;
        $c->limit(1);
        $c->find(true);
        
        $aur = array_merge( $c->toArray(),$aur);
        
        if (empty($c->company_id_logo_id_id))  {
                 
            $im = DB_Dataobject::factory('Images');
            $im->ontable = 'Companies';
            $im->onid = $c->id;
            $im->imgtype = 'LOGO';
            $im->limit(1);
            $im->selectAs($im,  'company_id_logo_id_%s');
            if ($im->find(true)) {
                    
                foreach($im->toArray() as $k=>$v) {
                    $aur[$k] = $v;
                }
            }
        }
        
        // i18n language and coutry lists.
        
        
        $lang = empty($au->lang) ? 'en' : $au->lang;
        if (empty($_SESSION['Pman_I18N'][$lang])) {
            require_once 'Pman/I18N.php';
            $x = new Pman_I18N();
            $x->setSession($au);
            
        }
        
        $aur['i18n'] =$_SESSION['Pman_I18N'][$lang];
        
        // perms + groups.
        $aur['perms']  = $au->getPerms();
        $g = DB_DataObject::Factory('Group_Members');
        $aur['groups']  = $g->listGroupMembership($au, 'name');
        
        $aur['passwd'] = '';
        $aur['dailykey'] = '';
        
        /** -- these need modulizing somehow! **/
        
        if ($this->hasModule('Fax')) {
            // should check fax module???
            $f = DB_DataObject::factory('Fax_Queue');
            $aur['faxMax'] = $f->getMaxId();
            $aur['faxNumPending'] = $f->getNumPending();
        }
        
        if ($this->hasModule('Documents')) {
        // inbox...
            $d = DB_DataObject::factory('Documents_Tracking');
            $d->person_id = $au->id;
            //$d->status = 0; // unread
            $d->whereAdd('date_read IS NULL');
            $d->applyFilters(array('query'=> array('unread' => 1)), $au);
            $aur['inbox_unread'] = $d->count();
        }
        
        //echo '<PRE>';print_r($aur);
        
        $this->jok($aur);
        exit;
        
            
    }

    
    var $domObj = false;
    function post()
    {
        
        if (!empty($_REQUEST['passwordRequest'])) { //|| (strpos($_REQUEST['username'], '@') < 1)) {
            
            return $this->passwordRequest($_REQUEST['passwordRequest']);
            
        }
        
        if (!empty($_REQUEST['changePassword'])) {
            return $this->changePassword($_REQUEST);
        }
        
        
        $u = DB_DataObject::factory('Person');
        //$u->active = 1;
        $u->whereAdd('LENGTH(passwd) > 1');
        //$u->company_id = $this->company->id;
        
        if (empty($_REQUEST['username'])) { //|| (strpos($_REQUEST['username'], '@') < 1)) {
            $this->jerr('You typed the wrong Username or Password (0)');
            exit;
        }
         
        $u->email = $_REQUEST['username'];
        if ($u->count() > 1 || !$u->find(true)) {
            $this->jerr('You typed the wrong Username or Password  (1)');
            exit;
        }
        
        if (!$u->active) {
            $this->jerr('Account disabled');
        }
        
        if ($u->checkPassword($_REQUEST['password'])) {
            $u->login();
            $this->AddEvent("LOGIN");
            if (!empty($_REQUEST['lang']) && $_REQUEST['lang'] != $u->lang) {
                $uu = clone($u);
                $uu->lang = $_REQUEST['lang'];
                $uu->update();
            }
             // log it..
            
            $this->sendAuthUserDetails();
            exit;

            //exit;
        }
        
         
        $this->jerr('You typed the wrong Username or Password  (2)'); // - " . htmlspecialchars(print_r($_POST,true))."'");
        exit;
    }
    
    function passwordRequest($n) 
    {
        $u = DB_DataObject::factory('Person');
        //$u->company_id = $this->company->id;
        
        $u->whereAdd('LENGTH(passwd) > 1');
        $u->email = $n;
        $u->active = 1;
        if ($u->count() > 1 || !$u->find(true)) {
            $this->jerr('invalid User (1)');
        }
        // got a avlid user..
        if (!strlen($u->passwd)) {
            $this->jerr('invalid User (2)');
        }
        // check to see if we have sent a request before..
        
        if ($u->no_reset_sent > 3) {
            $this->jerr('We have issued to many resets - please contact the Administrator');
        }
        $this->authFrom = time();
        $this->authKey = $u->genPassKey($this->authFrom);
        $this->authKey = md5($u->email . $this->authFrom . $u->passwd);
        
        $ret =  $u->sendTemplate('password_reset', $this);
        if (is_object($ret)) {
            $this->addEvent('SYSERR',false, $ret->getMessage());
            $this->jerr($ret->getMessage());
        }
        $this->addEvent('PASSREQ',$u, $u->email);
        $uu = clone($u);
        $uu->no_reset_sent++;
        $uu->update($u);
        $this->jok("done");
        
        
    }
    function changePassword($r)
    {
        $au = $this->getAuthUser();
        if ($au) {
            $uu = clone($au);
            $au->setPassword($r['passwd1']);
            $au->update($uu);
            $this->jok($au);
        }
        // not logged in -> need to validate 
        if (empty($r['passwordReset'])) {
            $this->jerr("invalid request");
        }
        // same code as reset pasword
       
        $bits = explode('/', $r['passwordReset']);
        //print_R($bits);
      
        $res= $this->resetPassword(@$bits[0],@$bits[1],@$bits[2]);
          
        if ($res !== false) {
            $this->jerr($res);
        }
        // key is correct.. let's change password...
        
        $u = DB_DataObject::factory('Person');
        
        //$u->company_id = $this->company->id;
        $u->whereAdd('LENGTH(passwd) > 1');
        $u->active = 1;
        if (!$u->get($bits[0])) {
           $this->jerr("invalid id"); // should not happen!!!!
        }
        $uu = clone($u);
        $u->setPassword($r['passwd1']);
        $u->update($uu);
        $u->login();
        
        $this->jok($u);
    }
    
    
    
}

