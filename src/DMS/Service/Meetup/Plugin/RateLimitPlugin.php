<?php

namespace DMS\Service\Meetup\Plugin;

use Guzzle\Common\Event;
use Guzzle\Http\Message\Response;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Class RateLimitPlugin
 *
 * This plugin watches the X-Rate-Limit headers of the Meetup API.
 *
 * Since we can only determine the rate limit numbers from a previous API calls within the same application request
 * We want to store those values to slow down the sequential calls within the same request exponantially after
 * a certain factor is hit.
 *
 * @package DMS\Service\Meetup\Plugin
 */
class RateLimitPlugin implements EventSubscriberInterface
{
    /**
     * Whether rate limiting is enabled
     *
     * @var bool $rateLimitEnabled
     */
    private $rateLimitEnabled = true;

    /**
     * At what factor between 0 and 1, should throttling be kicked in (now 50%)
     *
     * @var float $rateLimitFactor
     */
    private $rateLimitFactor = 0.5;

    /**
     * Number of API calls total for this window
     *
     * @var int $rateLimitMax
     */
    private $rateLimitMax = 30;

    /**
     * Number of API calls remaining before rate limit is hit
     *
     * @var int $rateLimitRemaining
     */
    private $rateLimitRemaining = 30;

    /**
     * Number of seconds before rate limit is reset
     *
     * @var int $rateLimitReset
     */
    private $rateLimitReset = 0;

    /**
     * Returns an array of event names this subscriber wants to listen to.
     *
     * The array keys are event names and the value can be:
     *
     *  * The method name to call (priority defaults to 0)
     *  * An array composed of the method name to call and the priority
     *  * An array of arrays composed of the method names to call and respective
     *    priorities, or 0 if unset
     *
     * For instance:
     *
     *  * array('eventName' => 'methodName')
     *  * array('eventName' => array('methodName', $priority))
     *  * array('eventName' => array(array('methodName1', $priority), array('methodName2'))
     *
     * @return array The event names to listen to
     *
     * @api
     */
    public static function getSubscribedEvents()
    {
        return array(
            'request.before_send' => array('onRequestBeforeSend', -1000),
            'request.success' => array('onRequestSuccess', -1000),
        );
    }

    /**
     * Request before-send event handler
     *
     * @param Event $event Event received
     * @return array
     */
    public function onRequestSuccess(Event $event)
    {
        /** @var Response $response */
        $response = $event['response'];
        $responseHeaders = $response->getHeaders()->toArray();

        if (!isset($responseHeaders['X-RateLimit-Limit'])) {
            $this->rateLimitEnabled = false;
            return;
        }

        $this->rateLimitMax = $responseHeaders['X-RateLimit-Limit'][0];
        $this->rateLimitRemaining = $responseHeaders['X-RateLimit-Remaining'][0];
        $this->rateLimitReset = $responseHeaders['X-RateLimit-Remaining'][0];

        // Prevent division by zero
        if ($this->rateLimitMax == 0) {
            $this->rateLimitMax = 1;
        }

        return;
    }

    /**
     * Performs slowdown when rate limiting is enabled and nearing it's limit
     *
     */
    public function onRequestBeforeSend()
    {
        $currentAmount = $this->rateLimitMax - $this->rateLimitRemaining;
        $currentFactor = $currentAmount / $this->rateLimitMax;

        // Perform slowdown if the factor is hit
        if ($currentFactor > $this->rateLimitFactor) {
            $microsecondsPerRequestRemaining = $this->rateLimitReset / $this->rateLimitRemaining * 1000000;

            usleep($microsecondsPerRequestRemaining);
        }

        return;
    }
}