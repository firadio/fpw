<?php

class CurlConcurrency {

    private $oCurlMulti;
    private $oCurlOptShare;

    public function __construct() {
        $this->oCurlMulti = curl_multi_init();
        $this->oCurlOptShare = curl_share_init();
        curl_share_setopt($this->oCurlOptShare, CURLSHOPT_SHARE, CURL_LOCK_DATA_SSL_SESSION);
    }

    private function header_mtoa($mHeader) {
        // 将Hastable类型转为Array
        $aHeader = array();
        foreach($mHeader as $sKey => $aValue) {
            if (is_array($aValue)) {
                foreach ($aValue as $sValue) {
                    $aHeader[] = $sKey . ': ' . $sValue;
                }
            } else {
                $aHeader[] = $sKey . ': ' . $aValue;
            }
        }
        return $aHeader;
    }

    private function curl_set_connect_to($ch, $url, $to_ip) {
        if (empty($to_ip)) {
            return;
        }
        $mCurl = parse_url($url);
        if (empty($mCurl['host'])) {
            return;
        }
        $port = '';
        if ($mCurl['scheme'] === 'http') {
            $port = isset($mCurl['port']) ? $mCurl['port'] : 80;
        }
        if ($mCurl['scheme'] === 'https') {
            $port = isset($mCurl['port']) ? $mCurl['port'] : 443;
        }
        if (empty($port)) {
            return;
        }
        $item = $mCurl['host'] . ':' . $port . ':' . $to_ip;
        curl_setopt($ch, CURLOPT_CONNECT_TO, array($item));
    }

    private function getNewCurl($sMethod, $sUrl, $mHeader, $sReqBody, $mOption) {
        $ch = curl_init();

        // 固定的设置
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);// 返回要输出到变量
        curl_setopt($ch, CURLOPT_PIPEWAIT, true);
        curl_setopt($ch, CURLOPT_TCP_KEEPALIVE, true);
        curl_setopt($ch, CURLOPT_TCP_KEEPIDLE, 120);
        curl_setopt($ch, CURLOPT_TCP_KEEPINTVL, 60);

        if (isset($mOption['CURLOPT_HEADER'])) {
             // 是否获取头部信息
            curl_setopt($ch, CURLOPT_HEADER, $mOption['CURLOPT_HEADER']);
        }

        // 设置HTTP版本
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2TLS);
        $alpn = isset($mOption['alpn']) ? $mOption['alpn'] : '';
        if ($alpn === 'h1') {
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        }
        if ($alpn === 'h2') {
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2TLS);
        }

        if (isset($mOption['CURLOPT_PRIVATE'])) {
            curl_setopt($ch, CURLOPT_PRIVATE, $mOption['CURLOPT_PRIVATE']);
        }

        // SSL相关设置
        curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_SESSIONID_CACHE, true);

        // 要修改的设置
        curl_setopt($ch, CURLOPT_SHARE, $this->oCurlOptShare);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $sMethod); // 第1个参数
        curl_setopt($ch, CURLOPT_URL, $sUrl); // 第2个参数

        // 设置连接IP地址
        if (isset($mOption['connect_to'])) {
            $this->curl_set_connect_to($ch, $sUrl, $mOption['connect_to']);
        }

        if ($sMethod === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $sReqBody); // 第4个参数
            $mHeader['content-length'] = strlen($sReqBody);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->header_mtoa($mHeader));

        return $ch;
    }

    public function getContent($aRequests) {
        list($sPrivateData, $sContent) = $this->getData($aRequests);
        return $sContent;
    }

    public function getData($aRequests) {
        // 并发执行CURL，哪个先返回就用哪个

        $aChs = array();
        foreach ($aRequests as $aRequest) {
            list($sMethod, $sUrl, $mHeader, $sReqBody, $mOption) = $aRequest;
            $ch = $this->getNewCurl($sMethod, $sUrl, $mHeader, $sReqBody, $mOption);
            curl_multi_add_handle($this->oCurlMulti, $ch);
            $aChs[] = $ch;
        }

        do {
            // 1：延迟1纳秒
            usleep(1);

            // 2：curl_multi_select
            curl_multi_select($this->oCurlMulti);

            // 3：curl_multi_exec
            $status = curl_multi_exec($this->oCurlMulti, $active);

            $bIsOK = false;
            $sPrivateData = '';
            $sContent = '';
            // 4：curl_multi_info_read
            while ($info = curl_multi_info_read($this->oCurlMulti)) {
                $ch = $info['handle'];
                if ($info['result'] !== CURLE_OK) {
                    continue;
                }

                // 获取 HTTP 状态码
                $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($status_code !== 200) {
                    continue;
                }

                $sPrivateData = curl_getinfo($ch, CURLINFO_PRIVATE);

                // 5：curl_multi_getcontent
                $sContent = curl_multi_getcontent($ch);
                if (!is_string($sContent)) {
                    continue;
                }

                $bIsOK = true;
                break;
            }

            if ($bIsOK) {
                foreach ($aChs as $ch) {
                    // 6：curl_multi_remove_handle 和 curl_close
                    curl_multi_remove_handle($this->oCurlMulti, $ch);
                    curl_close($ch);
                }
                return array($sPrivateData, $sContent);
            }

        } while ($active !== 0 && $status === CURLM_OK);

        return array(null, null);
    }

}
