<?php

class Pman_Login extends Pman {


    function get($base, $o= array())
    {
        require_once 'Pman/Core/Auth/State.php';
        $n = new Pman_Core_Auth_State();
        $n->post($base, $o);
        
        
        
    }
    
    function post($base, $o = array())
    {
        
        require_once 'Pman/Core/Auth/Login.php';
        $n = new Pman_Core_Auth_Login();
        $n->post($base, $o);
        
    }
    
    
    
    
}
