<?php

namespace TransformatorBundle\Utils;

use php_user_filter;

class FilterTranscode extends php_user_filter
{
    const FILTER_NAME = 'convert.transcode.';

    private $encoding_from = 'auto';

    private $encoding_to;

    public function onCreate()
    {
        if (strpos($this->filtername, self::FILTER_NAME) !== 0) {
            return false;
        }

        $params = substr($this->filtername, strlen(self::FILTER_NAME));
        if (! preg_match('/^([-\w]+)(:([-\w]+))?$/', $params, $matches)) {
            return false;
        }

        if (isset($matches[1])) {
            $this->encoding_from = $matches[1];
        }

        $this->encoding_to = mb_internal_encoding();
        if (isset($matches[3])) {
            $this->encoding_to = $matches[3];
        }

        $this->params['locale'] = setlocale(LC_CTYPE, '0');
        if (stripos($this->params['locale'], 'UTF-8') === false) {
            setlocale(LC_CTYPE, 'en_US.UTF-8');
        }

        return true;
    }

    public function onClose()
    {
        setlocale(LC_CTYPE, $this->params['locale']);
    }

    public function filter($in, $out, &$consumed, $closing)
    {
        while ($res = stream_bucket_make_writeable($in)) {
            $res->data = @iconv($this->encoding_from, $this->encoding_to, $res->data);
            $consumed += $res->datalen;
            stream_bucket_append($out, $res);
        }

        return PSFS_PASS_ON;
    }
}
