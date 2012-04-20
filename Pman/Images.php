<?php
/**
 * view permission should be required on the underlying object...
 * 
 * 
 * Use Cases:
 * 
 * args: ontable request
 *      ontable (req) tablename.
 *      filename
 *      (other table args)
 *      as (serve as a type) = eg. mimetype.
 * 
 * args: generic
 *     as :(serve as a type) = eg. mimetype.
 * 
 * Images/{ID}/fullname.xxxx
 * 
 * (valid thumbs 200, 400)...?
 * Images/Thumb/200/{ID}/fullname.xxxx
 * Images/Download/{ID}/fullname.xxxx
 * 
 */
require_once  'Pman.php';
class Pman_Images extends Pman
{
    function getAuth()
    {
        parent::getAuth(); // load company!
        //return true;
        $au = $this->getAuthUser();
        //if (!$au) {
        //    die("Access denied");
       // }
        $this->authUser = $au;
        
        return true;
    }
    var $thumb = false;
    var $as_mimetype = false;
    var $method = 'inline';
    
    function get($s) // determin what to serve!!!!
    {
        $this->as_mimetype = empty($_REQUEST['as']) ? '' : $_REQUEST['as'];
        
        $bits= explode('/', $s);
        $id = 0;
        // without id as first part...
        if (!empty($bits[0]) && $bits[0] == 'Thumb') {
            $this->thumb = true;
            $this->as_mimetype = 'image/jpeg';
            $this->size = empty($bits[1]) ? '0x0' : $bits[1];
            $id = empty($bits[2]) ? 0 :   $bits[2];
            
        } else if (!empty($bits[0]) && $bits[0] == 'Download') {
            $this->method = 'attachment';
            $id = empty($bits[1]) ? 0 :   $bits[1];
            
        } else  if (!empty($bits[1]) && $bits[1] == 'Thumb') { // with id as first part.
            $this->thumb = true;
            $this->as_mimetype = 'image/jpeg';
            $this->size = empty($bits[2]) ? '0x0' : $bits[2];
            $id = empty($bits[3]) ? 0 :   $bits[3];
            
        } else {
            $id = empty($bits[0]) ? 0 :  $bits[0];
        }
        
        if (strpos($id,':') > 0) {  // id format  tablename:id:-imgtype
            $onbits = explode(':', $id);
            if ((count($onbits) < 2)   || empty($onbits[1]) || !is_numeric($onbits[1]) || !strlen($onbits[0])) {
                die("Bad url");
            }
            //DB_DataObject::debugLevel(1);
            $img = DB_DataObjecT::factory('Images');
            $img->ontable = $onbits[0];
            $img->onid = $onbits[1];
            if (empty($_REQUEST['anytype'])) {
                $img->whereAdd("mimetype like 'image/%'");
            }
            
            if (isset($onbits[2])) {
                $img->imgtype = $onbits[2];
            }
            $img->limit(1);
            if (!$img->find(true)) {
                header('Location: ' . $this->rootURL . '/Pman/templates/images/file-broken.png?reason=' .
                urlencode("no images for that item: " . htmlspecialchars($id)));
            }
            
            $id = $img->id;
            
            
        }
        $id = (int) $id;
        
        // depreciated - should use ontable:onid:type here...
        if (!empty($_REQUEST['ontable'])) {

            //DB_DataObjecT::debugLevel(1);
            $img = DB_DataObjecT::factory('Images');
            $img->setFrom($_REQUEST);
            // use imgtype now...
           // if (!empty($_REQUEST['query']['filename'])){
           //     $img->whereAdd("filename LIKE '". $img->escape($_REQUEST['query']['filename']).".%'");
           // }
            
            
            $img->limit(1);
            if (!$img->find(true)) {
                header('Location: ' . $this->rootURL . '/Pman/templates/images/file-broken.png?reason='. 
                    urlencode("No file exists"));
            } 
            $id = $img->id;
            
        }
        
        
       
        $img = DB_DataObjecT::factory('Images');
        if (!$id || !$img->get($id)) {
             
            header('Location: ' . $this->rootURL . '/Pman/templates/images/file-broken.png?reason=' .
                urlencode("image has been removed or deleted."));
        }
        $this->serve($img);
        exit;
    }
 
    function serve($img)
    {
        require_once 'File/Convert.php';
        if (!file_exists($img->getStoreName())) {
            //print_r($img);exit;
            header('Location: ' . $this->rootURL . '/Pman/templates/images/file-broken.png?reason=' .
                urlencode("Original file was missing : " . $img->getStoreName()));
    
        }
        
        $x = new File_Convert($img->getStoreName(), $img->mimetype);
        if (empty($this->as_mimetype)) {
            $this->as_mimetype  = $img->mimetype;
        }
        if (!$this->thumb) {
            $x->convert( $this->as_mimetype);
            $x->serve($this->method);
            exit;
        }
        //echo "SKALING?  $this->size";
        $this->validateSize();
        $x->convert( $this->as_mimetype, $this->size);
        $x->serve();
        exit;
        
        
        
        
    }
    function validateSize()
    {
        $sizes = array(
                '100', 
                '100x100', 
                '150', 
                '150x150', 
                '200', 
                '200x0',
                '200x200',  
                '400x0',
                '300x100', // logo on login.
                '500'
            );
        
        // this should be configurable...
        $ff = HTML_FlexyFramework::get();
        if (!empty($ff->Pman_Images['sizes'])) {
            $sizes = array_merge($sizes , $ff->Pman_Images['sizes']);
        }
        
        
        if (!in_array($this->size, $sizes)) {
            die("invalid scale - ".$this->size);
        }
    }
}