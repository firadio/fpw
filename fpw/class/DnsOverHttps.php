<?php

/*
域名解析记录为TXT类型，内容是
{"hosts": ["60.12.70.108:379","39.172.91.235:379","47.90.101.1:379","5.28.39.4:379","43.129.10.152:379","39.99.237.243:379"],"protocol":"https:","alpn":"h2","server_name":"fpw.feieryun.cn","path":"/"}
*/

require_once(__DIR__ . '/RFC1035.php');
require_once(__DIR__ . '/CurlConcurrency.php');

class DnsOverHttps {

    /*
    * 参考文档
    * https://developers.cloudflare.com/1.1.1.1/encryption/dns-over-https/make-api-requests/dns-wireformat/
    * https://developers.google.com/speed/public-dns/docs/doh
    * https://datatracker.ietf.org/doc/html/rfc8484
    */

    private $oRFC1035;
    private $oCurlConcurrency;
    private $aServerAddrs = array();

    public function __construct() {
        $this->oRFC1035 = new RFC1035();
        $this->oCurlConcurrency = new CurlConcurrency();
        // 1. 定义DNS服务器的URL
        $this->aServerAddrs[] = '1.1.1.1'; // Cloudflare
        $this->aServerAddrs[] = '1.0.0.1'; // Cloudflare
        $this->aServerAddrs[] = '8.8.8.8'; // Google
        $this->aServerAddrs[] = '8.8.4.4'; // Google
        $this->aServerAddrs[] = '223.5.5.5'; // AliDns
        //$this->aServerAddrs[] = '223.6.6.6'; // AliDns
        $this->aServerAddrs[] = '1.12.12.12'; // DnsPod
        $this->aServerAddrs[] = '120.53.53.53'; // DnsPod
    }

    private function getAllRDatasInAnswersByResponse($mDnsRP) {

        $aRet = array();

        if (!isset($mDnsRP['questions'])) {
            return $aRet;
        }

        $aTypes = array();
        foreach ($mDnsRP['questions'] as $mQuestion) {
            if (!is_numeric($mQuestion['TYPE'])) {
                continue;
            }
            $aTypes[] = $mQuestion['TYPE'];
        }

        if (count($aTypes) === 0) {
            return $aRet;
        }

        if (!isset($mDnsRP['answers'])) {
            return $aRet;
        }

        foreach ($mDnsRP['answers'] as $mAnswer) {
            if (!isset($mAnswer['TYPE'])) {
                // 跳过没记录类型
                continue;
            }
            if (!in_array($mAnswer['TYPE'], $aTypes)) {
                // 跳过和查询TYPE不匹配的结果
                continue;
            }
            if (empty($mAnswer['RDATA'])) {
                // 跳过没RDATA的
                continue;
            }
            $aRet[] = $mAnswer['RDATA'];
        }

        return $aRet;

    }

    // 2. 定义要查询的域名和类型
    public function dnsQuery($name, $type = 'A') {

        $mOption = array();
        $mOption['CURLOPT_HEADER'] = false;

        // 3：要发送的Header头部
        $mHeader = array();
        $mHeader['accept'] = 'application/dns-message';
        $mHeader['content-type'] = 'application/dns-message';

        // 4. 构建DNS查询请求
        $sReqBody = $this->oRFC1035->constructDnsQueryPacket($name, $type);

        // 5：生成要并发的请求列表
        $aRequests = array();
        foreach ($this->aServerAddrs as $sServer) {
            $sUrl = 'https://' . $sServer . '/dns-query';
            $mOption['CURLOPT_PRIVATE'] = $sServer;
            $aRequests[] = array('POST', $sUrl, $mHeader, $sReqBody, $mOption);
        }

        // 6. 发送HTTP请求到DoH服务器
        $callback = function ($sPrivateData, $response) {
            $mDRP = $this->oRFC1035->parseDnsResponsePacket($response);
            $aRdatas = $this->getAllRDatasInAnswersByResponse($mDRP);
            if (count($aRdatas) === 0) {
                return;
            }
            $mRet = array();
            $mRet['server'] = $sPrivateData;
            $mRet['rdatas'] = $aRdatas;
            return $mRet;
        };

        return $this->oCurlConcurrency->getData($aRequests, $callback);

    }
}
