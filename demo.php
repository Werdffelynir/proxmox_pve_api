<?php

require('src/PveAPI.php');

$configure = array(
    'hostname' => '0.0.0.0',
    'username' => 'username',
    'userpass' => 'userpass',
    'realm' => 'pve',
    'port' => 8006
);

// create new instance
$pve = new PveAPI($configure);

// enable debug
$pve->debug(true);

// for testing check auth
if($pve->login())
{
    $version = $pve->getVersion();
    $nodes = $pve->getListNodes();
    $vms = array();

    foreach($nodes as $node)
    {
    	$vms[] = $pve->getListVms($node);
    }

    print_r($vms);
}

// show debug info
$pve->debugPrint();

