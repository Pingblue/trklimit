<?php

require_once( dirname(__FILE__).'/../../php/settings.php' );
require_once( dirname(__FILE__).'/../../php/rtorrent.php' );
require_once( dirname(__FILE__).'/../../php/xmlrpc.php' );

// Load plugin configuration
$confFile = dirname(__FILE__).'/conf.php';
if(file_exists($confFile)) {
    include($confFile);
}

if(!function_exists('getUser')) {
    function getUser() {
        return User::getUser();
    }
}

@define('MAX_SPEED', 100*1024*1024);

$theSettings = rTorrentSettings::get();
$inited = false;

// Check interval in seconds (how often to check torrents)
$checkInterval = 60; // Check every 60 seconds

$req = new rXMLRPCRequest( array(
    new rXMLRPCCommand( "get_upload_rate" ),
    new rXMLRPCCommand( "get_download_rate" )
));

if($req->success())
{
    $req1 = new rXMLRPCRequest();
    if($req->val[0]==0)
        $req1->addCommand(new rXMLRPCCommand( "set_upload_rate", MAX_SPEED ));
    if($req->val[1]==0)
        $req1->addCommand(new rXMLRPCCommand( "set_download_rate", MAX_SPEED ));

    if(!$req1->getCommandsCount() || $req1->success())
    {
        // Set up throttle limits
        $req = new rXMLRPCRequest( array(
            new rXMLRPCCommand("throttle_up", array("trklimit", $MAX_UL_LIMIT."")),
            new rXMLRPCCommand("throttle_down", array("trklimit", $MAX_DL_LIMIT.""))
        ));

        if($req->success())
        {
            // Schedule the check script to run periodically
            $checkScript = dirname(__FILE__).'/check.php';
            $req2 = new rXMLRPCRequest(
                $theSettings->getAbsScheduleCommand('tracklimits_check', $checkInterval,
                    'execute={sh,-c,'.escapeshellarg(Utility::getPHP()).' '.
                    escapeshellarg($checkScript).' '.escapeshellarg(getUser()).' &}'
                )
            );

            if($req2->success())
            {
                $theSettings->registerPlugin($plugin["name"], $pInfo["perms"]);
                $inited = true;
            }
        }
    }
}

if(!$inited)
    $jResult .= "plugin.disable(); noty('tracklimits: '+theUILang.pluginCantStart,'error');";
