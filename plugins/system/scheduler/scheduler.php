<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  System.scheduler
 *
 * @copyright   Copyright (C) 2005 - 2018 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\Registry\Registry;

/**
 * Joomla! scheduler plugin
 *
 * @since  __DEPLOY_VERSION__
 */
class PlgSystemScheduler extends JPlugin
{
	/**
	 * Load plugin language files automatically
	 *
	 * @var    boolean
	 * @since  __DEPLOY_VERSION__
	 */
	protected $autoloadLanguage = true;

	/**
	 * The scheduler is triggered after the response is sent.
	 *
	 * @return  void
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function onAfterRespond()
	{
		$startTime = microtime(true);

		// Get the timeout for Joomla! system scheduler
		/** @var \Joomla\Registry\Registry $params */
		$cache_timeout = (int) $this->params->get('cachetimeout', 1);
		$cache_timeout = 60 * $cache_timeout;

		// Do we need to run? Compare the last run timestamp stored in the plugin's options with the current
		// timestamp. If the difference is greater than the cache timeout we shall not execute again.
		$now  = time();
		$last = (int) $this->params->get('lastrun', 0);

		if ((abs($now - $last) < $cache_timeout))
		{
			return;
		}

		JLog::add(
			'Main Scheduler',
			JLog::INFO,
			'scheduler'
		);

		// Update last run status
		$this->params->set('lastrun', $now);

		$db = JFactory::getDbo();
		$query = $db->getQuery(true)
					->update($db->quoteName('#__extensions'))
					->set($db->quoteName('params') . ' = ' . $db->quote($this->params->toString('JSON')))
					->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
					->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
					->where($db->quoteName('element') . ' = ' . $db->quote('scheduler'));

		try
		{
			// Lock the tables to prevent multiple plugin executions causing a race condition
			$db->lockTable('#__extensions');
		}
		catch (Exception $e)
		{
			// If we can't lock the tables it's too risky to continue execution
			return;
		}

		try
		{
			// Update the plugin parameters
			$result = $db->setQuery($query)->execute();

			$this->clearCacheGroups(array('com_plugins'), array(0, 1));
		}
		catch (Exception $exc)
		{
			// If we failed to execite
			$db->unlockTables();
			$result = false;
		}

		try
		{
			// Unlock the tables after writing
			$db->unlockTables();
		}
		catch (Exception $e)
		{
			// If we can't lock the tables assume we have somehow failed
			$result = false;
		}

		// Abort on failure
		if (!$result)
		{
			return;
		}

		// Check all tasks plugin and if needed trigger those
		$this->checkAndTrigger();

		// Log the time it took to run
		$endTime    = microtime(true);
		$timeToLoad = sprintf('%0.2f', $endTime - $startTime);
		JLog::add(
			'Main Scheduler took ' . $timeToLoad . ' seconds',
			JLog::INFO,
			'scheduler'
		);
	}

	/**
	 * Check and Trigger .
	 *
	 * @return  void
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	private function checkAndTrigger()
	{
		// Looks for cronnable plugin
		$db = JFactory::getDbo();
		$query = $db->getQuery(true)
			->select($db->quoteName(array('extension_id', 'name', 'params')))
			->from($db->quoteName('#__extensions'))
			->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
			->where($db->quoteName('folder') . ' = ' . $db->quote('cronnable'));

		$db->setQuery($query);

		try
		{
			$tasks = $db->loadObjectList();
		}
		catch (RuntimeException $e)
		{
			return;
		}

		if (empty($tasks))
		{
			return;
		}

		// Unleash hell
		JPluginHelper::importPlugin('cronnable');
		$dispatcher = JEventDispatcher::getInstance();

		foreach ($tasks as $task)
		{
			$taskParams = json_decode($task->params, true);
			
			$now  = time();
			$last = (int) $taskParams['lastrun'];
			$cache_timeout = (int) $taskParams['cachetimeout'];
			$cache_timeout = 60 * $cache_timeout;

			if ((abs($now - $last) < $cache_timeout))
			{
				continue;
			}

			$startTime = microtime(true);
			JLog::add(
				'Main Scheduler:' . $task->extension_id . ':' . $task->name,
				JLog::INFO,
				'scheduler'
			);

			// Update lastrun
			$this->updateLastRun($task->extension_id, $task->params);
			$dispatcher->trigger('onExecuteScheduledTask', array($this));
			$endTime    = microtime(true);
			$timeToLoad = sprintf('%0.2f', $endTime - $startTime);
			JLog::add(
				'Main Scheduler:' . $task->extension_id . ':' . $task->name . ' tooks ' . $timeToLoad . ' seconds',
				JLog::INFO,
				'scheduler'
			);
		}
	}

	/**
	 * Clears cache groups. We use it to clear the plugins cache after we update the last run timestamp.
	 *
	 * @param   array  $clearGroups   The cache groups to clean
	 * @param   array  $cacheClients  The cache clients (site, admin) to clean
	 *
	 * @return  void
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	private function clearCacheGroups(array $clearGroups, array $cacheClients = array(0, 1))
	{
		$conf = JFactory::getConfig();

		foreach ($clearGroups as $group)
		{
			foreach ($cacheClients as $client_id)
			{
				try
				{
					$options = array(
						'defaultgroup' => $group,
						'cachebase'    => $client_id ? JPATH_ADMINISTRATOR . '/cache' :
							$conf->get('cache_path', JPATH_SITE . '/cache')
					);

					$cache = JCache::getInstance('callback', $options);
					$cache->clean();
				}
				catch (Exception $e)
				{
					// Ignore it
				}
			}
		}
	}

	/**
	 * Update last run.
	 *
	 * @param   integer   $eid     The plugin id
	 * @param   registry  $params  The plugin parameters
	 *
	 * @return  void
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	private function updateLastRun($eid, $params)
	{
		// Update last run status
		$registry = new Registry($params);
		$registry->set('lastrun', time());

		$db = JFactory::getDbo();
		$query = $db->getQuery(true)
					->update($db->quoteName('#__extensions'))
					->set($db->quoteName('params') . ' = ' . $db->quote($registry->toString('JSON')))
					->where($db->quoteName('extension_id') . ' = ' . $eid);

		try
		{
			// Lock the tables to prevent multiple plugin executions causing a race condition
			$db->lockTable('#__extensions');
		}
		catch (Exception $e)
		{
			// If we can't lock the tables it's too risky to continue execution
			return;
		}

		try
		{
			// Update the plugin parameters
			$result = $db->setQuery($query)->execute();

			$this->clearCacheGroups(array('com_plugins'), array(0, 1));
		}
		catch (Exception $exc)
		{
			// If we failed to execite
			$db->unlockTables();
			$result = false;
		}

		try
		{
			// Unlock the tables after writing
			$db->unlockTables();
		}
		catch (Exception $e)
		{
			// If we can't lock the tables assume we have somehow failed
			$result = false;
		}

		// Abort on failure
		if (!$result)
		{
			return;
		}
	}
}
