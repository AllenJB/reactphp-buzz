<?php

namespace Clue\React\Buzz\Io;

use React\HttpClient\Client as HttpClient;
use Clue\React\Buzz\Message\Request;
use Clue\React\Buzz\Message\Response;
use React\HttpClient\Request as RequestStream;
use React\HttpClient\Response as ResponseStream;
use React\Promise\Deferred;
use Clue\React\Buzz\Message\Headers;
use Clue\React\Buzz\Message\Body;
use React\EventLoop\LoopInterface;
use React\Dns\Resolver\Factory as ResolverFactory;
use React\SocketClient\Connector;
use React\SocketClient\SecureConnector;

class Sender
{
    /**
     * create a new default sender attached to the given event loop
     *
     * @param LoopInterface $loop
     * @return self
     */
    public static function createFromLoop(LoopInterface $loop)
    {
        $dnsResolverFactory = new ResolverFactory();
        $resolver = $dnsResolverFactory->createCached('8.8.8.8', $loop);

        $connector = new Connector($loop, $resolver);
        $secureConnector = new SecureConnector($connector, $loop);

        $ref = new \ReflectionClass('React\HttpClient\Client');
        if ($ref->getConstructor()->getNumberOfRequiredParameters() == 2) {
            // react/http-client:0.4 removed the $loop parameter
            $http = new HttpClient($connector, $secureConnector);
        } else {
            $http = new HttpClient($loop, $connector, $secureConnector);
        }

        return new self($http);
    }

    private $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    public function send(Request $request)
    {
        $body = $request->getBody();
        if (!$body->isEmpty()) {
            //$request->setHeader('Content-Length', $body->getLength());
        }

        $deferred = new Deferred();

        $requestStream = $this->http->request($request->getMethod(), $request->getUrl(), $request->getHeaders()->getAll());
        $requestStream->end((string)$body);

        $requestStream->on('error', function($error) use ($deferred) {
            $deferred->reject($error);
        });

        $requestStream->on('response', function (ResponseStream $response) use ($deferred) {
            $bodyBuffer = '';
            $response->on('data', function ($data) use (&$bodyBuffer) {
                $bodyBuffer .= $data;
                // progress
            });

            $response->on('end', function ($error = null) use ($deferred, $response, &$bodyBuffer) {
                if ($error !== null) {
                    $deferred->reject($error);
                } else {
                    $deferred->resolve(new Response(
                        'HTTP/' . $response->getVersion(),
                        $response->getCode(),
                        $response->getReasonPhrase(),
                        new Headers($response->getHeaders()),
                        new Body($bodyBuffer)
                    ));
                }
            });
        });

        return $deferred->promise();
    }
}
