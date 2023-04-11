<?php

/**
 * FPW Request 类
 */
class Request {

    public $sUserIP;
    public $sMethod;
    public $sUrl;
    public $mHeader;
    public $sBody;

    private $mUrl;
    private $sPath;
    private $mQuery;
    private $mForm;

    private function getUrlValue($sKey) {
        if (!$this->mUrl) {
            $this->mUrl = parse_url($this->sUrl);
        }
        return $this->mUrl[$sKey];
    }

    public function getPath() {
        if (!$this->sPath) {
            $sPath = $this->getUrlValue('path');
            $sPath = str_replace('..', '', $sPath);
            $this->sPath = $sPath;
        }
        return $this->sPath;
    }

    public function getQuery() {
        if (!$this->mQuery) {
            parse_str($this->getUrlValue('query'), $this->mQuery);
        }
        return $this->mQuery;
    }

    public function getForm() {
        if (!$this->mForm) {
            parse_str($this->sBody, $this->mForm);
        }
        return $this->mForm;
    }

}
