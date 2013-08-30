<?php
/**
 * Insert Fabrik Content into Joomla Articles
 *
 * @package     Joomla.Plugin
 * @subpackage  Content.fabrik
 * @copyright   Copyright (C) 2005-2013 fabrikar.com - All rights reserved.
 * @license     GNU/GPL http://www.gnu.org/copyleft/gpl.html
 */

// No direct access
defined('_JEXEC') or die('Restricted access');

jimport('joomla.plugin.plugin');

/**
 * Fab tweet content plugin - Twitter user time line module
 *
 * @package     Joomla.Plugin
 * @subpackage  Content.FabTweet
 * @since       3.1
*/

class PlgContentFab_Tweet extends JPlugin
{

	/**
	 * Constructor
	 *
	 * For php4 compatability we must not use the __constructor as a constructor for plugins
	 * because func_get_args ( void ) returns a copy of all passed arguments NOT references.
	 * This causes problems with cross-referencing necessary for the observer design pattern.
	 *
	 * @param   object  &$subject  The object to observe
	 * @param   object  $params    The object that holds the plugin parameters
	 *
	 * @since       1.5
	 */

	public function plgContentFab_Tweet(&$subject, $params = null)
	{
		parent::__construct($subject, $params);
	}

	/**
	 *  Prepare content method
	 *
	 * Method is called by the view
	 *
	 * @param   string  $context  The context of the content being passed to the plugin.
	 * @param   object  &$row     The article object.  Note $article->text is also available
	 * @param   object  &$params  The article params
	 * @param   int     $page     The 'page' number
	 *
	 * @return  void
	 */

	public function onContentPrepare($context, &$row, &$params, $page = 0)
	{
		jimport('joomla.html.parameter');
		jimport('joomla.filesystem.file');

		$botRegex = 'fab_tweet';

		// Simple performance check to determine whether bot should process further
		if (JString::strpos($row->text, $botRegex) === false)
		{
			return true;
		}

		JHTML::script('plugins/content/fab_tweet/timeago.js');
		JHTML::_('stylesheet', 'plugins/content/fab_tweet/fab_tweet.css');
		$document = JFactory::getDocument();
		$document->addScriptDeclaration('jQuery(document).ready(function() {
			jQuery("time.timeago").timeago();
	});');

		$regex = "/{" . $botRegex . "}*.*?{\/" . $botRegex . "}/i";
		$row->text = preg_replace_callback($regex, array($this, 'replace'), $row->text);
	}

	/**
	 * the function called from the preg_replace_callback - replace the {} with the correct HTML
	 *
	 * @param   string  $match  Plug-in match
	 *
	 * @return  string
	 */

	protected function replace($match)
	{
		$screenName = str_replace(array('{/fab_tweet}', '{fab_tweet}'), '', $match[0]);
		$screenName = trim(strip_tags($screenName));
		$plugin = JPluginHelper::getPlugin('content', 'fab_tweet');
		$fparams = new JRegistry($plugin->params);
		if ($screenName !== '')
		{
			$fparams->set('screen_name', $screenName);
		}
		$result = self::getList($fparams);
		return $result;
	}

	/**
	 * Get a (possibly cached) list of tweets
	 *
	 * @param   JRegistry  &$params  The plugin options.
	 *
	 * @return  array
	 */

	public static function getList(&$params)
	{
		$cache = JCache::getInstance('callback', array(
				'defaultgroup' => 'plg_conent_fab_tweet',
				'cachebase' => JPATH_BASE . '/cache/',
				'lifetime' => ( (float) $params->get('cachetime', 1) * 60 * 60 ),
				'language' => 'en-GB',
				'storage' => 'file')
		);
		$cache->setCaching((bool) $params->get('cached', true));

		// Create a layout object and ask it to render the sidebar
		$layout = new JLayoutFile('layouts.plg_content_fab_tweet.default', JPATH_SITE . '/plugins/content/fab_tweet');

		try
		{
			$rows = $cache->call(array('PlgContentFab_Tweet', 'getTweets'), $params);
			$rows = array_slice($rows, 0, (int) $params->get('length', 5));
			$html = $layout->render($rows);
		}
		catch (Exception $e)
		{
			$cache->clean();
			throw new ErrorException($e->getMessage(), 501);
			$rows = array();
			$html = $layout->render($rows);
		}
		return $html;
	}

	/**
	 * Ask twitter for tweets
	 *
	 * @param   JRegistry  $params  The plugin options.
	 *
	 * @return  array
	 */

	public static function getTweets($params)
	{
		if (!class_exists('TwitterOAuth'))
		{
			require_once JPATH_SITE . '/plugins/content/fab_tweet/abraham-twitteroauth/twitteroauth/twitteroauth.php';
		}

		$consumer_key = trim($params->get('consumer_key', ''));
		$consumer_secret = trim($params->get('consumer_secret', ''));
		$token = trim($params->get('oauth_token', ''));
		$secret = trim($params->get('oauth_token_secret', ''));

		$connection = new TwitterOAuth($consumer_key, $consumer_secret, $token, $secret);

		$data = array();
		$data['screen_name'] = $params->get('screen_name', '');
		$data['count'] = $params->get('count', 10);

		$endPoint = self::getEndPoint($params);
		$json = $connection->get($endPoint, $data);
		if (isset($json->errors))
		{
			$e = $json->errors[0];
			throw new RuntimeException('Fab tweet:' . $e->message, $e->code);
			return;
		}
		foreach ($json as &$item)
		{
			$date = JFactory::getDate($item->created_at);
			$item->created_at = $date->toSQL();
			self::links($item->text);
		}
		return $json;
	}

	/**
	 * Get end point
	 *
	 * @param   JRegistry  $params  Module parameters
	 *
	 * @return string
	 */
	protected static function getEndPoint($params)
	{
		switch ($params->get('timeline'))
		{
			case 'search':
				return 'https://api.twitter.com/1.1/search/tweets.json';
				break;
			case 'home':
				return 'https://api.twitter.com/1.1/statuses/home_timeline.json';
				break;
			case 'mentions':
				return 'https://api.twitter.com/1.1/statuses/mentions_timeline.json';
				break;
			case 'user':
			default:
				return 'https://api.twitter.com/1.1/statuses/user_timeline.json?';
		}
	}

	/**
	 * Convert URLs usernams, and hashtags to links
	 *
	 * @param   string  &$text  text to translate
	 *
	 * @return  void
	 */
	public static function links(&$text)
	{
		// Change all the urls to links
		$text = preg_replace("!http://(\\S+)!i", "<a href=\"http://$1\">http://$1</a>", $text);

		// @usernames
		$text = preg_replace("/@(\\w+)/", "<a href=\"http://twitter.com/$1\">@$1</a>", $text);

		// #hashtags too
		$text = preg_replace("/#(\\w+)/", "<a href=\"http://twitter.com/search?q=$1\">#$1</a>", $text);
	}

}
