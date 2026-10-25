<?php

// This script runs every 60 seconds to check and manage torrents

if(count($argv) > 1)
    $_SERVER['REMOTE_USER'] = $argv[1];

require_once( dirname(__FILE__).'/../../php/xmlrpc.php' );

// Debug logging toggle: set to true to enable the debug log
$debug = false;
$logFile = '/tmp/tracklimits_check_debug.log';

// Writes to the log only when $debug is true
function dbg($msg) {
    global $debug, $logFile;
    if($debug)
        file_put_contents($logFile, $msg, FILE_APPEND);
}

// Errors are always logged, regardless of $debug
function errlog($msg) {
    global $logFile;
    file_put_contents($logFile, date('Y-m-d H:i:s') . " - " . $msg, FILE_APPEND);
}

dbg(date('Y-m-d H:i:s') . " - Check script started\n");

// Load plugin configuration
$confFile = dirname(__FILE__).'/conf.php';
if(file_exists($confFile)) {
    include($confFile);
    dbg("Config loaded\n");
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

dbg("Sending XMLRPC request...\n");

if(!$req->run() || $req->fault) {
    errlog("XMLRPC FAILED\n");
    if($req->fault) {
        errlog("Fault: " . print_r($req->val, true) . "\n");
    }
    exit(1);
}

dbg("Got " . (count($req->val) / 7) . " torrents\n");

$commandsToRun = new rXMLRPCRequest();
$hasCommands = false;
$publicCount = 0;
$dhtCount = 0;

// Process torrents (every 7 items is one torrent)
for($i = 0; $i < count($req->val); $i += 7) {
    $hash = $req->val[$i];
    $name = $req->val[$i + 1];
    $xthrottle = $req->val[$i + 2];
    $currentThrottle = $req->val[$i + 3];
    $complete = $req->val[$i + 4];
    $isOpen = $req->val[$i + 5];
    $isActive = $req->val[$i + 6];

    dbg("\nProcessing: " . substr($name, 0, 40) . "\n");

    // Get trackers for this torrent
    $reqTrackers = new rXMLRPCRequest(new rXMLRPCCommand("t.multicall", array($hash, "", "t.url=")));
    if(!$reqTrackers->run() || $reqTrackers->fault) {
        errlog("ERROR: Could not get trackers for " . substr($name, 0, 40) . "\n");
        continue;
    }

    // Build tracker string
    $trackerString = '';
    $trackerCount = 0;
    $hasRealTracker = false;

    foreach($reqTrackers->val as $tracker) {
        $trackerLower = strtolower($tracker);
        $trackerString .= $trackerLower . '#';
        $trackerCount++;

        // Check if it's a real tracker (not dht://)
        if(!empty($trackerLower) && strpos($trackerLower, 'dht://') !== 0) {
            $hasRealTracker = true;
        }
    }

    dbg("  Tracker count: $trackerCount\n");
    if($trackerCount > 0) {
        dbg("  Trackers: " . substr($trackerString, 0, 100) . "\n");
    }

    // Check if it's a public tracker or DHT-only
    $isPublic = false;
    $isDHT = false;

    // DHT-only: either no trackers at all, or only dht:// trackers
    if(empty($trackerString) || !$hasRealTracker) {
        // No trackers or only DHT = DHT-only torrent
        $isPublic = true;
        $isDHT = true;
        $dhtCount++;
        dbg("  → DHT-only (no real trackers)\n");
    } else {
        // Check against restricted tracker list
        foreach($restrictedTrackers as $trk) {
            if(stripos($trackerString, strtolower($trk)) !== false) {
                $isPublic = true;
                $publicCount++;
                dbg("  → PUBLIC (matched: $trk)\n");
                break;
            }
        }
        if(!$isPublic) {
            dbg("  → PRIVATE (no match)\n");
        }
    }

    // Apply actions based on public/private status
    if($isPublic) {
        // Mark as Public (both DHT and public trackers use same label)
        if($xthrottle != 'Public') {
            $commandsToRun->addCommand(new rXMLRPCCommand('d.custom.set', array($hash, 'x-throttle', 'Public')));
            $hasCommands = true;
            dbg("  Action: Set x-throttle=Public\n");
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
            dbg("  Action: Apply throttle\n");
        }

        // Close if complete and preventUpload is enabled
        if($preventUpload && $complete == 1 && $isOpen == 1) {
            $commandsToRun->addCommand(new rXMLRPCCommand('d.close', array($hash)));
            $hasCommands = true;
            dbg("  Action: Close (completed)\n");
        }
    } else {
        // Mark as private
        if($xthrottle != 'Private' && $xthrottle != '') {
            $commandsToRun->addCommand(new rXMLRPCCommand('d.custom.set', array($hash, 'x-throttle', 'Private')));
            $hasCommands = true;
            dbg("  Action: Set x-throttle=Private\n");
        }
    }
}

dbg("\nSummary - Public: $publicCount, DHT-only: $dhtCount, Has commands: " . ($hasCommands ? "YES" : "NO") . "\n");

// Execute all commands
if($hasCommands) {
    dbg("Executing commands...\n");
    if($commandsToRun->run() && !$commandsToRun->fault) {
        dbg("Commands executed successfully\n");
    } else {
        errlog("Commands FAILED: " . print_r($commandsToRun->val, true) . "\n");
    }
}

dbg("Script complete\n\n");
exit(0);
