<?php

require_once 'Pman.php';

class Pman_Login extends Pman {


    // DONT USE THIS - it's all in pman/core/auth now.

    function get($base, $o= array())
    {
        $this->jnotice("INVALIDURL","Invalid request");
        /*
        require_once 'Pman/Core/Auth/State.php';
        $n = new Pman_Core_Auth_State();
        $n->post($base, $o);
        
        */
        
    }
    
    function post($base, $o = array())
    {
        $this->jnotice("INVALIDURL","Invalid request");
        /*
        require_once 'Pman/Core/Auth/Login.php';
        $n = new Pman_Core_Auth_Login();
        $n->post($base, $o);
        */
    }
    
    
    
    
}
