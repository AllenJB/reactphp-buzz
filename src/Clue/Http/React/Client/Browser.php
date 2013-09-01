<?php

namespace Clue\Http\React\Client;

use React\Stream\Stream;
use React\HttpClient\Client as HttpClient;
use React\EventLoop\LoopInterface;
use Clue\Http\React\Client\Response\BufferedResponse;
use Clue\Http\React\Client\Message\Request\Request;

class Browser
{
    private $http;
    private $loop;

    public function __construct(LoopInterface $loop, HttpClient $http)
    {
        $this->http = $http;
        $this->loop = $loop;
    }

    public function get($url, $headers = array())
    {
        return $this->request('GET', $url, $headers);
    }

    public function post($url, $headers = array(), $content = '')
    {
        return $this->request('POST', $url, $headers, $content);
    }

    public function head($url, $headers = array())
    {
        return $this->request('HEAD', $url, $headers);
    }

    public function patch($url, $headers = array(), $content = '')
    {
        return $this->request('PATCH', $url , $headers, $content);
    }

    public function put($url, $headers = array(), $content = '')
    {
        return $this->request('PUT', $url, $headers, $content);
    }

    public function delete($url, $headers = array(), $content = '')
    {
        return $this->request('DELETE', $url, $headers, $content);
    }

    public function submit($url, array $fields, $headers = array(), $method = 'POST')
    {
        $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        $content = http_build_query($fields);

        return $this->request($method, $url, $headers, $content);
    }

    public function download($url, $target, $headers = array(), $method = 'GET')
    {
        $stream = $this->getTargetStream($target);

        $response = $this->requestStream($method, $url, $headers);
        $response->pipe($stream);

        return $response;
    }

    public function request($method, $url, $headers = array(), $content = null)
    {
        $request = new Request($method, $url, $headers);
        $request->send($this->http, $content);

        return $request;
    }

    private function getTargetStream($target)
    {
        if ($target instanceof Stream) {
            $stream = $target;
        } elseif (is_resource($target)) {
            $stream = new Stream($target, $this->loop);
        } else {
            $resource = fopen($target, 'w+');
            if ($target === false) {
                throw new \RuntimeException('Unable to open target stream to write output to');
            }
            $stream = new Stream($resource, $this->loop);
        }
    }
}
