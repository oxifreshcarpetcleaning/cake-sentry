<?php

namespace Connehito\CakeSentry\Middleware;

use App\Model\Entity\User;
use Authentication\IdentityInterface;
use Cake\Core\Configure;
use Cake\Core\InstanceConfigTrait;
use Cake\Event\Event;
use Cake\Event\EventManager;
use Cake\Http\ServerRequest;
use Cake\Utility\Hash;
use Connehito\CakeSentry\Event\SentrySpanTracingEvents;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Sentry\SentrySdk;
use Sentry\State\Scope;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\TransactionContext;
use function Sentry\configureScope;
use function Sentry\init;

/**
 * Class SentryMiddleware
 *
 * Registers the error handler with the Event Manager, and adds some additional metadata about the
 * thread and the user.
 *
 * @package App\Middleware
 * @author Andy Hoffner <andy@oxifresh.com>
 */
class SentryMiddleware implements MiddlewareInterface
{
    use InstanceConfigTrait;

    /**
     * The current active transaction.
     *
     * @var \Sentry\Tracing\Transaction|null
     */
    protected $transaction;

    /**
     * The span for the `app.handle` part of the application.
     *
     * @var \Sentry\Tracing\Span|null
     */
    protected $appSpan;

    /**
     * @var array
     */
    protected $_defaultConfig = [
        'identityAttribute' => 'identity',
    ];

    /**
     * Callable implementation for the middleware stack.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The request.
     * @param \Psr\Http\Server\RequestHandlerInterface $handler The request handler.
     *
     * @return \Psr\Http\Message\ResponseInterface A response.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Don't run in the dev environment
        // TODO remove the false from testing:
        if (false && Configure::read('dev_mode')) {
            return $handler->handle($request);
        }

        // Grab the identity set by Authentication or Authorization Middleware, if any
        $attribute = $this->getConfig('identityAttribute');
        $identity = $request->getAttribute($attribute);

        // Pass it to the Sentry error context event handler and setup
        $this->setupClient($request, $identity);

        // Register event listener to trace performance on specific Events
        EventManager::instance()->on(new SentrySpanTracingEvents());

        // Start the Sentry transaction for performance monitoring
        $this->startTransaction($request);

        // Call the other chained middleware after
        $response = $handler->handle($request);

        // After everything is done, log the termination with sentry for performance monitoring
        $this->terminate($request, $response);

        return $response;
    }

    /**
     * setupClient
     *
     * @param ServerRequestInterface $request
     * @param IdentityInterface|User|null $user
     *
     */
    protected function setupClient(ServerRequestInterface $request, IdentityInterface $user = null): void
    {
        $config = (array)Configure::read('Sentry');
        if (!Hash::check($config, 'dsn')) {
            throw new RuntimeException('Sentry DSN not provided.');
        }

        init($config);

        $event = new Event('CakeSentry.Client.afterSetup', $this);
        EventManager::instance()->dispatch($event);

        /** @var ServerRequest $request */
        $request->trustProxy = true;

        $hub = SentrySdk::getCurrentHub();

        $release = Configure::read('git.project') . '@' . Configure::read('git.version');
        if (Configure::read('dev_mode')) {
            $release .= '-dev';
        }

        $options = $hub->getClient()->getOptions();
        $options->setRelease($release);

        if (isset($user)) {
            configureScope(function (Scope $scope) use ($request, $user) {
                $userInfo = array_filter([
                    'id' => $user->id,
                    'email' => (string)$user->email,
                    'ip_address' => $request->clientIp(),
                    'metadata' => [
                        'name' => (string)$user->full_name
                    ]
                ]);

                $scope->setUser($userInfo);
                $scope->setTags([
                    'type' => $request->is(['ajax', 'json', 'csv']) ? 'api' : 'browser'
                ]);
            });
        }
    }

    /**
     * startTransaction
     *
     * @param ServerRequestInterface $request
     *
     */
    private function startTransaction(ServerRequestInterface $request): void
    {
        $sentry = SentrySdk::getCurrentHub();

        $path = '/' . ltrim($request->getUri(), '/');
        $fallbackTime = microtime(true);
        $sentryTraceHeader = $request->getHeader('sentry-trace');

        $context = $sentryTraceHeader
            ? TransactionContext::fromSentryTrace($sentryTraceHeader)
            : new TransactionContext;

        $context->setOp('SchedulingCenter');
        $context->setName($path);
        $context->setData([
            'url' => $path,
            'method' => strtoupper($request->getMethod()),
        ]);

        $context->setStartTimestamp(($request->getServerParams()['REQUEST_TIME_FLOAT'] ?? $fallbackTime));

        $this->transaction = $sentry->startTransaction($context);

        // Setting the Transaction on the Hub
        SentrySdk::getCurrentHub()->setSpan($this->transaction);


        // @TODO: We might want to move this together with the `RouteMatches` listener to some central place and or do this from the `EventHandler`
        // @TODO: We might want to move this together with the `RouteMatches` listener to some central place and or do this from the `EventHandler`
        $spanContextStart = new SpanContext();
        $spanContextStart->setOp('bootstrap');
        $spanContextStart->setStartTimestamp(($request->getServerParams()['REQUEST_TIME_FLOAT'] ?? $fallbackTime));
        $spanContextStart->setEndTimestamp(microtime(true));
        $this->transaction->startChild($spanContextStart);

        $appContextStart = new SpanContext();
        $appContextStart->setOp('controller.invoke');
        $appContextStart->setStartTimestamp(microtime(true));
        $this->appSpan = $this->transaction->startChild($appContextStart);

        SentrySdk::getCurrentHub()->setSpan($this->appSpan);
    }

    /**
     * Handle the application termination.
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     *
     * @return void
     */
    public function terminate(ServerRequestInterface $request, ResponseInterface $response): void
    {
        if ($this->transaction !== null) {
            if ($this->appSpan !== null) {
                $this->appSpan->finish();
            }
            // Make sure we set the transaction and not have a child span in the Sentry SDK
            // If the transaction is not on the scope during finish, the trace.context is wrong
            SentrySdk::getCurrentHub()->setSpan($this->transaction);

            $this->hydrateRequestData($request);

            $this->hydrateResponseData($response);

            $this->transaction->finish();
        }
    }

    /**
     * hydrateRequestData
     *
     * @param ServerRequestInterface|ServerRequest $request
     *
     */
    private function hydrateRequestData(ServerRequestInterface $request): void
    {
        $routeName = $request->getParam('controller') ?? '<unlabeled transaction>';

        $this->transaction->setName($routeName);
        $this->transaction->setData([
            'name' => $routeName,
            'action' => $request->getParam('action')
        ]);

    }

    /**
     * hydrateResponseData
     *
     * @param ResponseInterface $response
     *
     */
    private function hydrateResponseData(ResponseInterface $response): void
    {
        $this->transaction->setHttpStatus($response->getStatusCode());
    }
}
