<?php

namespace Clue\React\Buzz\Io;

use Clue\React\Buzz\Message\MessageFactory;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop\LoopInterface;
use React\HttpClient\Client as HttpClient;
use React\HttpClient\Request as RequestStream;
use React\HttpClient\Response as ResponseStream;
use React\Promise;
use React\Promise\Deferred;
use React\Socket\Connector;
use React\SocketClient\SecureConnector;
use React\SocketClient\ConnectorInterface as LegacyConnectorInterface;
use React\Stream\ReadableStreamInterface;
use React\Socket\UnixConnector;

class Sender
{
    /**
     * create a new default sender attached to the given event loop
     *
     * This method is used internally to create the "default sender".
     * If you need custom DNS or connector settings, you're recommended to
     * explicitly create a HttpClient instance yourself and pass this to the
     * constructor of this method manually like this:
     *
     * ```php
     * $connector = new \React\Socket\Connector($loop);
     * $client = new \React\HttpClient\Client($loop, $connector);
     * $sender = new \Clue\React\Buzz\Io\Sender($client);
     * $browser = new \Clue\React\Buzz\Browser($loop, $sender);
     * ```
     *
     * @param LoopInterface $loop
     * @return self
     */
    public static function createFromLoop(LoopInterface $loop)
    {
        return new self(new HttpClient($loop));
    }

    /**
     * [deprecated] create sender attached to the given event loop and DNS resolver
     *
     * @param LoopInterface   $loop
     * @param \React\Dns\Resolver\Resolver|string $dns  DNS resolver instance or IP address
     * @return self
     * @deprecated as of v1.2.0, see createFromLoop()
     * @see self::createFromLoop()
     */
    public static function createFromLoopDns(LoopInterface $loop, $dns)
    {
        return new self(new HttpClient($loop, new Connector($loop, array(
            'dns' => $dns
        ))));
    }

    /**
     * [deprecated] create sender attached to given event loop using the given legacy connectors
     *
     * @param LoopInterface $loop
     * @param LegacyConnectorInterface $connector            default legacy connector to use to establish TCP/IP connections
     * @param LegacyConnectorInterface|null $secureConnector secure legacy connector to use to establish TLS/SSL connections (optional, composed from given default connector)
     * @return self
     * @deprecated as of v1.2.0, see createFromLoop()
     * @see self::createFromLoop()
     */
    public static function createFromLoopConnectors(LoopInterface $loop, LegacyConnectorInterface $connector, LegacyConnectorInterface $secureConnector = null)
    {
        if ($secureConnector === null) {
            $secureConnector = new SecureConnector($connector, $loop);
        }

        // react/http v0.5 requires the new Socket-Connector, so we upcast from the legacy SocketClient-Connectors here
        return new self(new HttpClient($loop, new Connector($loop, array(
            'tcp' => new ConnectorUpcaster($connector),
            'tls' => new ConnectorUpcaster($secureConnector),
            'dns' => false,
            'timeout' => false
        ))));
    }

    /**
     * create a sender that sends *everything* through given UNIX socket path
     *
     * @param LoopInterface $loop
     * @param string        $path
     * @return self
     */
    public static function createFromLoopUnix(LoopInterface $loop, $path)
    {
        return new self(
            new HttpClient(
                $loop,
                new FixedUriConnector(
                    $path,
                    new UnixConnector($loop)
                )
            )
        );
    }

    private $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    /**
     *
     * @internal
     * @param RequestInterface $request
     * @param MessageFactory $messageFactory
     * @return PromiseInterface Promise<ResponseInterface, Exception>
     */
    public function send(RequestInterface $request, MessageFactory $messageFactory)
    {
        $uri = $request->getUri();

        // URIs are required to be absolute for the HttpClient to work
        if ($uri->getScheme() === '' || $uri->getHost() === '') {
            return Promise\reject(new \InvalidArgumentException('Sending request requires absolute URI with scheme and host'));
        }

        $body = $request->getBody();

        // automatically assign a Content-Length header if the body size is known
        if ($body->getSize() !== null && $body->getSize() !== 0 && $request->hasHeader('Content-Length') !== null) {
            $request = $request->withHeader('Content-Length', $body->getSize());
        }

        if ($body instanceof ReadableStreamInterface && $body->isReadable() && !$request->hasHeader('Content-Length')) {
            $request = $request->withHeader('Transfer-Encoding', 'chunked');
        }

        $headers = array();
        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        $deferred = new Deferred();

        $requestStream = $this->http->request($request->getMethod(), (string)$uri, $headers, $request->getProtocolVersion());

        $requestStream->on('error', function($error) use ($deferred) {
            $deferred->reject($error);
        });

        $requestStream->on('response', function (ResponseStream $responseStream) use ($deferred, $messageFactory) {
            // apply response header values from response stream
            $deferred->resolve($messageFactory->response(
                $responseStream->getVersion(),
                $responseStream->getCode(),
                $responseStream->getReasonPhrase(),
                $responseStream->getHeaders(),
                $responseStream
            ));
        });

        if ($body instanceof ReadableStreamInterface) {
            if ($body->isReadable()) {
                if ($request->hasHeader('Content-Length')) {
                    // length is known => just write to request
                    $body->pipe($requestStream);
                } else {
                    // length unknown => apply chunked transfer-encoding
                    // this should be moved somewhere else obviously
                    $body->on('data', function ($data) use ($requestStream) {
                        $requestStream->write(dechex(strlen($data)) . "\r\n" . $data . "\r\n");
                    });
                    $body->on('end', function() use ($requestStream) {
                        $requestStream->end("0\r\n\r\n");
                    });
                }
            } else {
                // stream is not readable => end request without body
                $requestStream->end();
            }
        } else {
            // body is fully buffered => write as one chunk
            $requestStream->end((string)$body);
        }

        return $deferred->promise();
    }
}
