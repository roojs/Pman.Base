<?php

/// provide language data!!!
// DEPRECIATED - moved to Pman_Core_I18N
/**
 * 
 * 
 */

require_once 'Pman/Core/I18n.php';
class Pman_I18N extends Pman_Core_I18n
{
     
    
    
    function getAuth()
    {
        parent::getAuth(); // load company!
        //return true;
        $au = $this->getAuthUser();
        //if (!$au) {
        //    $this->jerr("Not authenticated", array('authFailure' => true));
        //}
        $this->authUser = $au;
         
         
        return true;
    }
    // returns a list of all countries..
     
    
    
    function setSession($au)
    {
        $this->authUser = $au;
        $lbits = implode('_', $this->guessUsersLanguage());
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
            // part of parent!!!!
            /*
            case 'BuildDB':
            // by admin only?!?
                //DB_DataObject::debugLevel(1);
                $this->buildDb('l');
                $this->buildDb('c');
                $this->buildDb('m');
                die("DONE!");
                break;
            */      
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
        $lang = !$au || empty($au->lang ) ? 'en' : (is_string($au) ? $au : $au->lang);
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
    
     
    
}
