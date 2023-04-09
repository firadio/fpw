<?php

require_once __DIR__ . '/class/Worker.php';

$oWorker = new Worker();
$oWorker->sFramework = getenv('FPW_FRAMEWORK');
$oWorker->sAutoload = getenv('FPW_AUTOLOAD');
$oWorker->iTimeout = 3600;
$oWorker->bIsDebug = false;
$oWorker->sServerUrl = getenv('FPW_URL');
$oWorker->fpwInfo['host'] = getenv('FPW_HOST');
$oWorker->fpwInfo['token'] = getenv('FPW_TOKEN');
$oWorker->sWwwrootDir = getenv('FPW_PUBLIC');
$oWorker->aDefaDocument = array('index.htm', 'index.html');
$oWorker->init();

echo "Firadio PHP Worker is Running for {$oWorker->fpwInfo['host']}\r\n";

$oWorker->run(function ($mReqHeader, $sReqBody, $sReqPath, $mReqQuery, $mReqForm) use ($oWorker) {
    $time = date('H:i:s');
    echo "{$time} {$mReqHeader['x-forwarded-for']} {$mReqHeader['url']}\r\n";
    $mFpwHeader = array();
    $mFpwHeader['content-type'] = 'text/html;charset=utf-8';
    if ($oWorker->FileServer($sReqPath, $mFpwHeader, $sResBody)) {
        return array(200, $mFpwHeader, $sResBody);
    }
    if ($oWorker->ThinkPHPWorker($sReqPath, $mReqHeader, $sReqBody, $iStatusCode, $mFpwHeader, $sResBody)) {
        return array($iStatusCode, $mFpwHeader, $sResBody);
    }
    $sResBody = 'File Not Found: ' . $sReqPath;
    return array(404, $mFpwHeader, $sResBody);
});
