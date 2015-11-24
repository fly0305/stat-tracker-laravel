<?php

namespace PragmaRX\Tracker;

use PragmaRX\Support\Config;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Log\Writer as Logger;
use PragmaRX\Tracker\Support\Minutes;
use Illuminate\Foundation\Application as Laravel;
use PragmaRX\Tracker\Data\RepositoryManager as DataRepositoryManager;

class Tracker
{
    protected $config;

	/**
	 * @var \Illuminate\Routing\Router
	 */
	protected $route;

    protected $logger;
	/**
	 * @var \Illuminate\Foundation\Application
	 */
	protected $laravel;

	protected $enabled = true;

	protected $sessionData;

	public function __construct(
                                    Config $config,
                                    DataRepositoryManager $dataRepositoryManager,
                                    Request $request,
                                    Router $route,
                                    Logger $logger,
								    Laravel $laravel
                                )
    {
        $this->config = $config;

        $this->dataRepositoryManager = $dataRepositoryManager;

        $this->request = $request;

	    $this->route = $route;

        $this->logger = $logger;

	    $this->laravel = $laravel;
    }

    public function boot()
    {
        if ($this->isTrackable())
        {
            $this->track();
        }
    }

    public function track()
    {
        $log = $this->getLogData();

        if ($this->config->get('log_enabled'))
        {
            $this->dataRepositoryManager->createLog($log);
        }
    }

	/**
	 * @return array
	 */
	protected function getSessionData()
	{
		$sessionData = array(
			'user_id' => $this->getUserId(),
			'device_id' => $this->getDeviceId(),
			'client_ip' => $this->request->getClientIp(),
			'geoip_id' => $this->getGeoIpId(),
			'agent_id' => $this->getAgentId(),
			'referer_id' => $this->getRefererId(),
			'cookie_id' => $this->getCookieId(),
			'is_robot' => $this->isRobot(),

			// The key user_agent is not present in the sessions table, but
			// it's internally used to check if the user agent changed
			// during a session.
			'user_agent' => $this->dataRepositoryManager->getCurrentUserAgent(),
		);

		return $this->sessionData = $this->dataRepositoryManager->checkSessionData($sessionData, $this->sessionData);
	}

	/**
	 * @return array
	 */
	protected function getLogData()
	{
		return array(
			'session_id' => $this->getSessionId(true),
			'method' => $this->request->method(),
			'path_id' => $this->getPathId(),
			'query_id' => $this->getQueryId(),
            'referer_id' => $this->getRefererId(),
			'is_ajax' => $this->request->ajax(),
			'is_secure' => $this->request->isSecure(),
			'is_json' => $this->request->isJson(),
			'wants_json' => $this->request->wantsJson(),
		);
	}

    public function getSessionId($updateLastActivity = false)
    {
        return $this->dataRepositoryManager->getSessionId(
            $this->getSessionData(),
            $updateLastActivity
        );
    }

    public function getUserId()
    {
	    return $this->config->get('log_users')
			    ? $this->dataRepositoryManager->getCurrentUserId()
			    : null;
    }

    public function getCookieId()
    {
	    return $this->config->get('store_cookie_tracker')
		        ? $this->dataRepositoryManager->getCookieId()
		        : null;
    }

    public function getDeviceId()
    {
	    return $this->config->get('log_devices')
		    ?   $this->dataRepositoryManager->findOrCreateDevice(
			        $this->dataRepositoryManager->getCurrentDeviceProperties()
		        )
		    : null;
    }

	public function getPathId()
	{
		return $this->config->get('log_paths')
			?   $this->dataRepositoryManager->findOrCreatePath(
					array(
						'path' => $this->request->path()
					)
				)
			: null;
	}

	public function getQueryId()
	{
		if ($this->config->get('log_queries'))
		{
			if (count($arguments = $this->request->query()))
			{
				return $this->dataRepositoryManager->getQueryId(
					array(
						'query' => array_implode('=', '|', $arguments),
						'arguments' => $arguments
					)
				);
			}
		}
	}

	public function routerMatched($log)
	{
	    if ($this->dataRepositoryManager->routeIsTrackable($this->route))
	    {		    if ($log)
		    {
			    $this->dataRepositoryManager->updateRoute(
				    $this->getRoutePathId()
			   );
		    }
	    }
	    // Router was matched but this route is not trackable
	    // Let's just delete the stored data, because There's not a
	    // realy clean way of doing this because if a route is not
	    // matched, and this happens ages after the app is booted,
	    // we till need to store data from the request.
		else
		{			$this->turnOff();

			$this->deleteCurrentLog();
		}
	}

	public function trackVisit($route, $request)
	{
		$this->dataRepositoryManager->trackRoute($route, $request);
	}

	protected function getRefererId()
	{
		return $this->config->get('log_referers')
				?   $this->dataRepositoryManager->getRefererId(
						$this->request->headers->get('referer')
					)
				: null;
	}

	public function getDomainId($domain)
	{
		return $this->dataRepositoryManager->getDomainId($domain);
	}

	protected function getRoutePathId()
	{
		return $this->dataRepositoryManager->getRoutePathId($this->route, $this->request);
	}

	public function handleException($exception)
	{
		if ($this->config->get('log_enabled'))
		{
			$this->dataRepositoryManager->handleException($exception);
		}
	}

    public function allSessions()
    {
        return $this->dataRepositoryManager->getAllSessions();
    }

    public function parserIsAvailable()
    {        if ( ! $this->dataRepositoryManager->parserIsAvailable() )
        {
            $this->logger->error('Tracker: uaparser regex file not available. "Execute php artisan tracker:updateparser" to generate it.');

            return false;
        }

        return true;
    }

	protected function isTrackableIp()
	{
		return ! ipv4_in_range(
			$this->request->getClientIp(),
			$this->config->get('do_not_track_ips')
		);
	}

	protected function isTrackableEnvironment()
	{
		return ! in_array(
			$this->laravel->environment(),
			$this->config->get('do_not_track_environments')
		);	}

	public function logSqlQuery($query, $bindings, $time, $name)
	{
		if (
			$this->isTrackable() &&
			$this->config->get('log_enabled') &&
			$this->config->get('log_sql_queries') &&
			$this->isSqlQueriesLoggableConnection($name)
		)
		{
			$this->dataRepositoryManager->logSqlQuery($query, $bindings, $time, $name);
		}
	}

	protected function isSqlQueriesLoggableConnection($name)
	{
		return ! in_array(
			$name,
			$this->config->get('do_not_log_sql_queries_connections')
		);
	}

	protected function isTrackable()
	{
		return $this->config->get('enabled') &&
				$this->logIsEnabled() &&
				$this->parserIsAvailable() &&
				$this->isTrackableIp() &&
				$this->isTrackableEnvironment() &&
				$this->notRobotOrTrackable();
	}

	public function logEvents()
	{
		if (
			$this->isTrackable() &&
			$this->config->get('log_enabled') &&
			$this->config->get('log_events')
		)
		{
			$this->dataRepositoryManager->logEvents();
		}
	}

	public function trackEvent($event)
	{
		$this->dataRepositoryManager->trackEvent($event);
	}

	public function sessions($minutes = 1440, $results = true)
    {
        return $this->dataRepositoryManager->getLastSessions(Minutes::make($minutes), $results);
    }

	public function sessionLog($uuid, $results = true)
	{
		return $this->dataRepositoryManager->getSessionLog($uuid, $results);
	}

	public function pageViews($minutes, $results = true)
    {
    	return $this->dataRepositoryManager->pageViews(Minutes::make($minutes), $results);
    }

    public function pageViewsByCountry($minutes, $results = true)
    {
    	return $this->dataRepositoryManager->pageViewsByCountry(Minutes::make($minutes), $results);
    }

    public function users($minutes, $results = true)
    {
    	return $this->dataRepositoryManager->users(Minutes::make($minutes), $results);
    }

	public function events($minutes, $results = true)
	{
		return $this->dataRepositoryManager->events(Minutes::make($minutes), $results);
	}

	public function errors($minutes, $results = true)
	{
		return $this->dataRepositoryManager->errors(Minutes::make($minutes), $results);
	}

	public function currentSession()
	{
		return $this->dataRepositoryManager->sessionRepository->getCurrent();
	}

	public function isRobot()
	{
		return $this->dataRepositoryManager->isRobot();
	}

	protected function notRobotOrTrackable()
	{
		return
			! $this->isRobot() ||
			! $this->config->get('do_not_track_robots');
	}
	protected function getGeoIpId()
	{
		return $this->config->get('log_geoip')
				? $this->dataRepositoryManager->getGeoIpId($this->request->getClientIp())
				: null;
	}

	protected function getAgentId()
	{
		return $this->config->get('log_user_agents')
				? $this->dataRepositoryManager->getAgentId()
				: null;
	}
	public function logByRouteName($name, $minutes = null)
	{
		if ($minutes)
		{
			$minutes = Minutes::make($minutes);
		}

		return $this->dataRepositoryManager->logByRouteName($name, $minutes);
	}

	public function getConfig($key)
	{
		return $this->config->get($key);
	}

	protected function deleteCurrentLog()
	{
		$this->dataRepositoryManager->logRepository->delete();
	}

	protected function logIsEnabled()
	{
		return
			$this->config->get('log_enabled') ||
			$this->config->get('log_sql_queries') ||
			$this->config->get('log_sql_queries_bindings') ||
			$this->config->get('log_events') ||
			$this->config->get('log_geoip') ||
			$this->config->get('log_user_agents') ||
			$this->config->get('log_users') ||
			$this->config->get('log_devices') ||
			$this->config->get('log_referers') ||
			$this->config->get('log_paths') ||
			$this->config->get('log_queries') ||
			$this->config->get('log_routes') ||
			$this->config->get('log_exceptions');
	}
	public function isEnabled()
	{
		return $this->enabled;
	}

	protected function turnOff()
	{
		$this->enabled = false;
	}

	public function checkCurrentUser()
	{
		if ( ! $this->getSessionData()['user_id'] && $user_id = $this->getUserId())
		{
			return true;
		}

		return false;
	}
}
