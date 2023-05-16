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

    private function getNewCurl($sMethod, $sUrl, $mHeader, $sReqBody) {
        $ch = curl_init();

        // 固定的设置
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);// 返回要输出到变量
        curl_setopt($ch, CURLOPT_HEADER, false); // 是否获取头部信息
        curl_setopt($ch, CURLOPT_PIPEWAIT, true);
        curl_setopt($ch, CURLOPT_TCP_KEEPALIVE, true);
        curl_setopt($ch, CURLOPT_TCP_KEEPIDLE, 120);
        curl_setopt($ch, CURLOPT_TCP_KEEPINTVL, 60);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2TLS);
        curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_SESSIONID_CACHE, true);

        // 要修改的设置
        curl_setopt($ch, CURLOPT_SHARE, $this->oCurlOptShare);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $sMethod); // 第1个参数
        curl_setopt($ch, CURLOPT_URL, $sUrl); // 第2个参数

        if ($sMethod === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $sReqBody); // 第4个参数
            $mHeader['content-length'] = strlen($sReqBody);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->header_mtoa($mHeader));

        return $ch;
    }

    public function getOne($aRequests) {
        // 并发执行CURL，哪个先返回就用哪个

        $aChs = array();
        foreach ($aRequests as $aRequest) {
            list($sMethod, $sUrl, $mHeader, $sReqBody) = $aRequest;
            $ch = $this->getNewCurl($sMethod, $sUrl, $mHeader, $sReqBody);
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

            $curl_data = '';
            // 4：curl_multi_info_read
            while ($info = curl_multi_info_read($this->oCurlMulti)) {
                $ch = $info['handle'];
                if ($info['result'] !== CURLE_OK) {
                    continue;
                }

                // 5：curl_multi_getcontent
                $curl_data = curl_multi_getcontent($ch);

                if (is_string($curl_data)) {
                    break;
                }
            }

            if ($curl_data !== '') {
                foreach ($aChs as $ch) {
                    // 6：curl_multi_remove_handle 和 curl_close
                    curl_multi_remove_handle($this->oCurlMulti, $ch);
                    curl_close($ch);
                }
                return $curl_data;
            }

        } while ($active !== 0 && $status === CURLM_OK);

        return '';
    }

}
