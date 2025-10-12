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

    file_put_contents($logFile, "\nProcessing: " . substr($name, 0, 40) . "\n", FILE_APPEND);

    // Get trackers for this torrent
    $reqTrackers = new rXMLRPCRequest(new rXMLRPCCommand("t.multicall", array($hash, "", "t.url=")));
    if(!$reqTrackers->run() || $reqTrackers->fault) {
        file_put_contents($logFile, "  ERROR: Could not get trackers\n", FILE_APPEND);
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

    file_put_contents($logFile, "  Tracker count: $trackerCount\n", FILE_APPEND);
    if($trackerCount > 0) {
        file_put_contents($logFile, "  Trackers: " . substr($trackerString, 0, 100) . "\n", FILE_APPEND);
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
        file_put_contents($logFile, "  → DHT-only (no real trackers)\n", FILE_APPEND);
    } else {
        // Check against restricted tracker list
        foreach($restrictedTrackers as $trk) {
            if(stripos($trackerString, strtolower($trk)) !== false) {
                $isPublic = true;
                $publicCount++;
                file_put_contents($logFile, "  → PUBLIC (matched: $trk)\n", FILE_APPEND);
                break;
            }
        }
        if(!$isPublic) {
            file_put_contents($logFile, "  → PRIVATE (no match)\n", FILE_APPEND);
        }
    }

    // Apply actions based on public/private status
    if($isPublic) {
        // Mark as Public (both DHT and public trackers use same label)
        if($xthrottle != 'Public') {
            $commandsToRun->addCommand(new rXMLRPCCommand('d.custom.set', array($hash, 'x-throttle', 'Public')));
            $hasCommands = true;
            file_put_contents($logFile, "  Action: Set x-throttle=Public\n", FILE_APPEND);
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
            file_put_contents($logFile, "  Action: Apply throttle\n", FILE_APPEND);
        }

        // Close if complete and preventUpload is enabled
        if($preventUpload && $complete == 1 && $isOpen == 1) {
            $commandsToRun->addCommand(new rXMLRPCCommand('d.close', array($hash)));
            $hasCommands = true;
            file_put_contents($logFile, "  Action: Close (completed)\n", FILE_APPEND);
        }
    } else {
        // Mark as private
        if($xthrottle != 'Private' && $xthrottle != '') {
            $commandsToRun->addCommand(new rXMLRPCCommand('d.custom.set', array($hash, 'x-throttle', 'Private')));
            $hasCommands = true;
            file_put_contents($logFile, "  Action: Set x-throttle=Private\n", FILE_APPEND);
        }
    }
}

file_put_contents($logFile, "\nSummary - Public: $publicCount, DHT-only: $dhtCount, Has commands: " . ($hasCommands ? "YES" : "NO") . "\n", FILE_APPEND);

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
