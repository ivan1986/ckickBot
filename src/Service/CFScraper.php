<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\Attribute\Required;

class CFScraper
{
    #[Required] public LoggerInterface $logger;

    private string $url;

    public function __construct(#[Autowire(param:'env(SCRAPER)')] string $url)
    {
        $this->url = $url;
    }

    public function getCookie(string $url, string $useragent)
    {
        $cfMs = new \GuzzleHttp\Client([
            'base_uri' => $this->url,
        ]);
        $res = $cfMs->post('/cf-clearance-scraper', [
            'json' => [
                'mode' => 'waf-session',
                'url' => $url,
                'userAgent' => $useragent,
            ]
        ]);
        if ($res->getStatusCode() != 200) {
            $this->logger->error('CF error HTTP code for {url}', ['url' => $url]);
            return false;
        }
        $info = $res->getBody()->getContents();
        $info = json_decode($info, true);
        if ($info['code'] != 200) {
            $this->logger->error('CF error result code {code} for {url}', ['url' => $url, 'code' => $info['code']]);
            return false;
        }
        foreach ($info['cookies'] as $v) {
            if ($v['name'] == 'cf_clearance') {
                return $v['value'];
            }
        }
        $this->logger->error('CF error not found cookie for {url}', ['url' => $url, 'cookies' => $info['cookies']]);
        return false;
    }
}
