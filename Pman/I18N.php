<?php

/// provide language data!!!
// THIS IS PUBLICALLY ACCESSABLE..
/**
 * 
 * Either we load this as a standard array at start??
 * eg. after login...
 * 
 * or we treat it like a regular pulldown... - on demand...
 * 
 * big snag is that we can not include these until the user has specified their interface language.
 * 
 * If we do a global jS array?
 * Pman.Lang.en = [ 'en' , 'English']
 * 
 * If we do a standard 'store' version..
 * { total:  x, data [ { code: 'en', title: 'English' }] }
 * 
 * 
 * usage:
 * 
 * index.php/Pman/I18N/BuildDB -- buildes the database..
 * 
 * 
 * Config in index.php..
 * 
 *  'Pman_I18N' => array(
      'l' => array(
            'en', 'zh_CN',   'zh_HK',  'zh_TW', 'th', 'ko', 'ja', 'ms', 
            'id', // indonesian
            'tl', // tagalog
            'vi', //vietnamise
            'hi', // hindi
            'ta', // tamil
            '**', // other
        ), 
       'c' => '*',
       'm' => array( 'USD', 'HKD', 'GBP', 'CNY', 'SGD', 'JPY' )
    ), 

 * 
 */

require_once 'Pman.php';
class Pman_I18N extends Pman
{
    
    // these are the languages we support.
    var $cfg = array(
        'l' => array(
            'en', 'zh_CN',   'zh_HK',  'zh_TW', 'th', 'ko', 'ja', 'ms', 
            'id', // indonesian
            'tl', // tagalog
            'vi', //vietnamise
            'hi', // hindi
            'ta', // tamil
            '**', // other
        ),
        'c' => array(
             'AU', 'CN', 'HK', 'IN', 'ID', 'JP', 'MY', 'NZ', 'TW', 'SG', 'TH', 'KR', 'US', 'PH', 'VN','**'
        ),
        'm' => array(
            'USD', 'HKD', 'GBP', 'CNY', 'SGD', 'JPY'
        )
    );
    
    
     
    
    
    function getAuth()
    {
        parent::getAuth(); // load company!
        //return true;
        $au = $this->getAuthUser();
        //if (!$au) {
        //    $this->jerr("Not authenticated", array('authFailure' => true));
        //}
        $this->authUser = $au;
        
        $opts = PEAR::getStaticProperty('Pman_I18N', 'options');
        $opts = empty($opts)  ?  array() : $opts;
        
        
        foreach($opts as $k=>$v) {
            
            if ($v == '*') {
                $this->cfg[$k] = $this->getDefaultCfg($k);
                continue;
            }
            $this->cfg[$k] = is_array($v) ? $v  : explode(',', $v);
        }
        
        
        
        
        return true;
    }
    // returns a list of all countries..
    function getDefaultCfg($t) {
        $ret = array();
        switch ($t) {
            case 'c':
                require_once 'I18Nv2/Country.php';
                
                $c = new I18Nv2_Country('en');
                $ret =  array_keys($c->codes);
                $ret[] = '**';
                break;
            case 'l':
                require_once 'I18Nv2/Language.php';
                $c = new I18Nv2_Language('en');
                $ret =  array_keys($c->codes);
                $ret[] = '**';
                break;
            case 'm':
                require_once 'I18Nv2/Currency.php';
                $c = new I18Nv2_Currency('en');
                $ret =  array_keys($c->codes);
                $ret[] = '**';
                break;
        }
        foreach ($ret as $k=>$v) {
            $ret[$k] = strtoupper($v);
        }
        
        
        return $ret;
    }
    
    
    
    function setSession($au)
    {
        $this->authUser = $au;
        $lbits = implode('_', $this->findLang());
        if (empty($_SESSION['Pman_I18N'])) {
            $_SESSION['Pman_I18N']  = array();
        }
        
        $_SESSION['Pman_I18N'][$lbits] = array(
            'l' => $this->getList('l', $lbits),
            'c' => $this->getList('c', $lbits),
            'm' => $this->getList('m', $lbits),
        );
        
        
    }
      
    function getList($type, $inlang,$fi=false)
    {
        //$l = new I18Nv2_Language($inlang);
        //$c= new I18Nv2_Country($inlang);
        $filter = !$fi  ? false :  $this->loadFilter($type); // project specific languages..
       // print_r($filter);
        
        $ret = array();
        
        
        
        
        foreach($this->cfg[$type] as $k) {
            if (is_array($filter) && !in_array($k, $filter)) {
                continue;
            }
             
            $ret[] = array(
                'code'=>$k , 
                'title' => $this->translate($inlang, $type, $k)
            );
            continue;
            
        }
        // sort it??
        return $ret;
        
    }
     
    
    function findLang() {
         
        $lang = !$this->authUser || empty($this->authUser->lang ) ? 'en' : $this->authUser->lang;
        $lbits = explode('_', strtoupper($lang));
        $lbits[0] = strtolower($lbits[0]);
        require_once 'I18Nv2/Country.php';
        require_once 'I18Nv2/Language.php';
        $langs = new I18Nv2_Language('en');
        $countries = new I18Nv2_Country('en');
      //  print_r($langs);
        //print_R($lbits);
        if (!isset($langs->codes[strtolower($lbits[0])])) {
            $this->jerr('invalid lang');
        }
        if (!empty($lbits[1]) &&  !isset($countries->codes[$lbits[1]])) {  
            $this->jerr('invalid lang Country component');
            
        }
        return $lbits;
    }
    
    function get($s)
    {
        if (empty($s)) {
            die('no type');
        }
        
        $lbits = $this->findLang();
         
        
        
        
        switch($s) {
            case 'Lang': 
                $ret = $this->getList('l', $lbits[0],empty($_REQUEST['filter']) ? false : $_REQUEST['filter']);
                break;

            case 'Country':
                $ret = $this->getList('c', $lbits[0],empty($_REQUEST['filter']) ? false : $_REQUEST['filter']);
                break;
                
             case 'Currency':
                $ret = $this->getList('m', $lbits[0],empty($_REQUEST['filter']) ? false : $_REQUEST['filter']);
                break;
              
            case 'BuildDB':
            // by admin only?!?
                //DB_DataObject::debugLevel(1);
                $this->buildDb('l');
                $this->buildDb('c');
                $this->buildDb('m');
                die("DONE!");
                break;
                  
            default: 
                $this->jerr("ERROR");
        }
         
        $this->jdata($ret);
        exit;
        
    }
    function loadFilter($type)
    {
        // this code only applies to Clipping module
        if (!$this->authUser) {
            return false;
        }
        
        // this needs moving to it's own project
        
        if (!$this->hasModule('Clipping')) {
            return false;
        }
        if ($type == 'm') {
            return false;
        }
        
        //DB_DataObject::debugLevel(1);
        $q = DB_DataObject::factory('Projects');
        
        $c = DB_Dataobject::factory('Companies');
        $c->get($this->authUser->company_id);
        if ($c->comptype !='OWNER') {
            $q->client_id = $this->authUser->company_id;
        }
        $q->selectAdd();
        $col = ($type == 'l' ? 'languages' : 'countries');
        $q->selectAdd('distinct(' . ($type == 'l' ? 'languages' : 'countries').') as dval');
        $q->whereAdd("LENGTH($col) > 0");
        $q->find();
        $ret = array();
        $ret['**'] = 1;
        while ($q->fetch()) {
            $bits = explode(',', $q->dval);
            foreach($bits as $k) {
                $ret[$k] = true;
            }
        }
        return array_keys($ret);
        
    }
   
     
    function translateList($au, $type, $k)  
    {
        $ar = explode(',', $k);
        $ret = array();
        foreach($ar as $kk) {
            $ret[] = $this->translate($au, $type, $kk);
        }
        return implode(', ', $ret);
    }
     /**
     * translate
     * usage :
     * require_once 'Pman/I18N.php';
     * $x = new Pman_I18N();
     * $x->translate($this->authuser, 'c', 'US');
     * @param au - auth User
     * @param type = 'c' or 'l'
     * @param k - key to translate
     * 
     */
     
    function translate($au, $type, $k) 
    {
      
        static $cache;
        if (empty($k)) {
            return '??';
        }
        $lang = !$au || empty($au->lang ) ? 'en' : is_string($au) ? $au : $au->lang;
        $lbits = explode('_', strtoupper($lang));
        $lang = $lbits[0];
        
        if (!isset($cache[$lang])) {
            require_once 'I18Nv2/Country.php';
            require_once 'I18Nv2/Language.php';
            require_once 'I18Nv2/Currency.php';
            $cache[$lang] = array(
                'l' =>  new I18Nv2_Language($lang, 'UTF-8'),
                'c' => new I18Nv2_Country($lang, 'UTF-8'),
                'm' => new I18Nv2_Currency($lang, 'UTF-8')
            );
            //echo '<PRE>';print_r(array($lang, $cache[$lang]['c']));
        }
        if ($k == '**') {
            return 'Other / Unknown';
        }
    
        
        if ($type == 'l') {
            $tolang = explode('_', $k);
         
            $ret = $cache[$lang][$type]->getName($tolang[0]);
            if (count($tolang) > 1) {
                $ret.= '('.$tolang[1].')'; 
            }
            return $ret;
        }
        $ret = $cache[$lang][$type]->getName($k);
        //print_r(array($k, $ret));
        return $ret;
        
        
    }
    
    
    
    function buildDB($ltype= false, $inlang= false )
    {
        if ($ltype === false) {
            
            die("OOPS NO LTYPE");
        }
        if ($inlang == '**') {
            return; // dont bother building generic..
        }
        if ($inlang === false) {
            foreach( $this->cfg['l'] as $l) {
                $this->buildDB($ltype, $l);
            }
            return;
        }
        
        $list = $this->loadFilter($ltype); //$this->cfg[$ltype];
        
        //DB_DataObject::debugLevel(1);
        
        foreach($list as $lkey) {
            $x = DB_DataObject::factory('i18n');
            $x->ltype = $ltype;
            $x->lkey = $lkey;
            $x->inlang= $inlang;
            if ($x->find(true)) {
                $xx= clone($x);
                $x->lval = $this->translate($inlang, $ltype, $lkey);
                $x->update($xx);
                continue;
            }
            $x->lval = $this->translate($inlang, $ltype, $lkey);
            $x->insert();
            
        }
        
        
        
        
    }
    
}
