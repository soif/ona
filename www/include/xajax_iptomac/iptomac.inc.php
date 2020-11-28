<?php


// These are the functions we'll be exposing via Xajax
$xajax->registerFunction("iptomac");

// for when and if I switch to xajax 0.5.x
//$xajax->register(XAJAX_FUNCTION,"iptomac");


//////////////////////////////////////////////////////////////////////////////
// Xajax Server
// Grab the MAC Address from the IP Address
// $ip          = IP Address (value)
// $mac_field   = Field to Update
// 
//////////////////////////////////////////////////////////////////////////////
function iptomac($ip,$mac_field){
    $mac_address="";
    if($ip){
        //global $include;
        //$mac_address=shell_exec("{$include}/xajax_iptomac/iptomac.sh $ip");
        
        //update arp table with a ping then grab MAC address from arp table
        $mac_address=shell_exec('/bin/ping -c1 '.$ip.' > /dev/null;  /usr/sbin/arp -an | /bin/grep "('.$ip.')" | /usr/bin/awk \'{print $4}\'');
        
        //remove invalid results
        if(preg_match('#incomplete#',$mac_address) or !preg_match('#^([a-f0-9]{2}:){5}[a-f0-9]{2}$#i',$mac_address)){
            $mac_address='';
        }
        $mac_address=strtoupper($mac_address);
    } 
    $response = new xajaxResponse();
    $response->addAssign($mac_field, 'value', $mac_address);
    return $response;
}



?>