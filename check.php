<?php

// This script runs every 60 seconds to check and manage torrents

if(count($argv) > 1)
    $_SERVER['REMOTE_USER'] = $argv[1];

require_once( dirname(__FILE__).'/../../php/xmlrpc.php' );

// Debug log
$logFile = '/tmp/tracklimits_check_debug.log';
file_put_contents($logFile, date('Y-m-d H:i:s') . " - Check script started\n", FILE_APPEND);

// Load plugin configuration
$confFile = dirname(__FILE__).'/conf.php';
if(file_exists($confFile)) {
    include($confFile);
    file_put_contents($logFile, "Config loaded\n", FILE_APPEND);
}

// Get all torrents
$req = new rXMLRPCRequest(new rXMLRPCCommand('d.multicall', array(
    'main',
    'd.hash=',
    'd.name=',
    'd.custom=x-throttle',
    'd.throttle_name=',
    'd.complete=',
    'd.is_open=',
    'd.is_active='
)));

file_put_contents($logFile, "Sending XMLRPC request...\n", FILE_APPEND);

if(!$req->run() || $req->fault) {
    file_put_contents($logFile, "XMLRPC FAILED\n", FILE_APPEND);
    if($req->fault) {
        file_put_contents($logFile, "Fault: " . print_r($req->val, true) . "\n", FILE_APPEND);
    }
    exit(1);
}

file_put_contents($logFile, "Got " . (count($req->val) / 7) . " torrents\n", FILE_APPEND);

$commandsToRun = new rXMLRPCRequest();
$hasCommands = false;
$publicCount = 0;

// Process torrents (every 7 items is one torrent now)
for($i = 0; $i < count($req->val); $i += 7) {
    $hash = $req->val[$i];
    $name = $req->val[$i + 1];
    $xthrottle = $req->val[$i + 2];
    $currentThrottle = $req->val[$i + 3];
    $complete = $req->val[$i + 4];
    $isOpen = $req->val[$i + 5];
    $isActive = $req->val[$i + 6];

    // Get trackers for this torrent
    $reqTrackers = new rXMLRPCRequest(new rXMLRPCCommand("t.multicall", array($hash, "", "t.url=")));
    if(!$reqTrackers->run() || $reqTrackers->fault) {
        continue;
    }

    // Build tracker string
    $trackerString = '';
    foreach($reqTrackers->val as $tracker) {
        $trackerString .= strtolower($tracker) . '#';
    }

    // Check if it's a public tracker
    $isPublic = false;
    foreach($restrictedTrackers as $trk) {
        if(stripos($trackerString, strtolower($trk)) !== false) {
            $isPublic = true;
            $publicCount++;
            file_put_contents($logFile, "Found public: " . substr($name, 0, 30) . "\n", FILE_APPEND);
            break;
        }
    }

    // Apply actions based on public/private status
    if($isPublic) {
        // Mark as public if not already marked
        if($xthrottle != 'Public') {
            $commandsToRun->addCommand(new rXMLRPCCommand('d.custom.set', array($hash, 'x-throttle', 'Public')));
            $hasCommands = true;
        }

        // Apply throttle if not already applied
        if($currentThrottle != 'trklimit') {
            // If torrent is active, need to stop, set throttle, then start
            if($isActive == 1) {
                $commandsToRun->addCommand(new rXMLRPCCommand('d.stop', array($hash)));
                $commandsToRun->addCommand(new rXMLRPCCommand('d.throttle_name.set', array($hash, 'trklimit')));
                $commandsToRun->addCommand(new rXMLRPCCommand('d.start', array($hash)));
            } else {
                $commandsToRun->addCommand(new rXMLRPCCommand('d.throttle_name.set', array($hash, 'trklimit')));
            }
            $hasCommands = true;
        }

        // Close if complete and preventUpload is enabled
        if($preventUpload && $complete == 1 && $isOpen == 1) {
            $commandsToRun->addCommand(new rXMLRPCCommand('d.close', array($hash)));
            $hasCommands = true;
            file_put_contents($logFile, "  Closing completed torrent: " . substr($name, 0, 30) . "\n", FILE_APPEND);
        }
    } else {
        // Mark as private
        if($xthrottle != 'Private' && $xthrottle != '') {
            $commandsToRun->addCommand(new rXMLRPCCommand('d.custom.set', array($hash, 'x-throttle', 'Private')));
            $hasCommands = true;
        }
    }
}

file_put_contents($logFile, "Public torrents found: $publicCount, Has commands: " . ($hasCommands ? "YES" : "NO") . "\n", FILE_APPEND);

// Execute all commands
if($hasCommands) {
    file_put_contents($logFile, "Executing commands...\n", FILE_APPEND);
    if($commandsToRun->run() && !$commandsToRun->fault) {
        file_put_contents($logFile, "Commands executed successfully\n", FILE_APPEND);
    } else {
        file_put_contents($logFile, "Commands FAILED: " . print_r($commandsToRun->val, true) . "\n", FILE_APPEND);
    }
}

file_put_contents($logFile, "Script complete\n\n", FILE_APPEND);
exit(0);
