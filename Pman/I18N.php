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
