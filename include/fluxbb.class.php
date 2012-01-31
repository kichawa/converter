<?php

/**
 * @copyright (C) 2012 FluxBB (http://fluxbb.org)
 * @license GPL - GNU General Public License (http://www.gnu.org/licenses/gpl.html)
 * @package FluxBB
 */

/**
 * Wrapper for FluxBB (has easy functions for adding rows to database etc.)
 */
class FluxBB
{
	public $db;
	public $db_config;
	public $pun_config;
	public $avatars_dir;

	public $tables = array(
		'bans'					=> array('id'),
		'categories'			=> array('id'),
		'censoring'				=> array('id'),
		'config'				=> 0,
		'forums'				=> array('id'),
		'forum_perms'			=> array('forum_id'),
		'groups'				=> array('g_id', 'g_id > 4'),
		'posts'					=> array('id'),
		'ranks'					=> array('id'),
		'reports'				=> array('id'),
		'topic_subscriptions'	=> array('topic_id'),
		'forum_subscriptions'	=> array('forum_id'),
		'topics'				=> array('id'),
		'users'					=> array('id', 'id > 1'),
	);

	function __construct($pun_config)
	{
		$this->pun_config = $pun_config;
		$this->avatars_dir = PUN_ROOT.rtrim($this->pun_config['o_avatars_dir'], '/').'/';
	}

	/**
	 * Connect to the FluxBB database
	 *
	 * @param array $db_config
	 */
	function connect_database($db_config)
	{
		$this->db_config = $db_config;

		$this->db = connect_database($db_config);
		$this->db->set_names('utf8');

		return $this->db;
	}

	/**
	 * Close database connection
	 */
	function close_database()
	{
		$this->db->end_transaction();
		$this->db->close();
	}

	function fetch_count()
	{
		$tables = array();
		foreach ($this->tables as $cur_table => $table_info)
		{
			$count = 0;
			if (is_array($table_info))
			{
				$query = array(
					'SELECT'	=> 'COUNT('.$this->db->escape($table_info[0]).')',
					'FROM'		=> $this->db->escape($cur_table)
				);
				if (isset($table_info[1]))
					$query['WHERE'] = $table_info[1];

				$result = $this->db->query_build($query) or conv_error('Unable to fetch num rows for '.$cur_table, __FILE__, __LINE__, $this->db->error());
				$count = $this->db->result($result);
			}

			$tables[$cur_table] = $count;
		}
		return $tables;
	}

	/**
	 * Adds a row to the FluxBB table with specified data
	 *
	 * @param string $table
	 * @param array $data Array containig data to insert into db
	 * @param mixed $error_callback	A function that will be called when error occurs
	 */
	function add_row($table, $data, $error_callback = null)
	{
	//	$fields = array_keys($this->schemas[$table]['FIELDS']);
//		$keys = array_keys($data);
//		$diff = array_diff($fields, $keys);

//		if (!$ignore_column_count && (count($fields) != count($keys) || !empty($diff)))
//			conv_error('Field list doesn\'t match for '.$table.' table.', __FILE__, __LINE__);

		$values = array();
		foreach ($data as $key => $value)
			$values[$key] = $value === null ? 'NULL' : '\''.$this->db->escape($value).'\'';

		$result = $this->db->query_build(array(
			'INSERT'	=> implode(', ', array_keys($values)),
			'INTO'		=> $table,
			'VALUES'	=> implode(', ', array_values($values)),
		)) or ($error_callback === null ? conv_error('Unable to insert values', __FILE__, __LINE__, $this->db->error()) : call_user_func($error_callback, $data));
	}

	/**
	 * Function called when a duplicate user is found
	 *
	 * @param array $cur_user
	 */
	function error_users($cur_user)
	{
		if (!isset($_SESSION['converter']['dupe_users']))
			$_SESSION['converter']['dupe_users'] = array();

		$_SESSION['converter']['dupe_users'][$cur_user['id']] = $cur_user;
	}

	/**
	 * Rename duplicate users
	 *
	 * @param array $cur_user
	 */
	function convert_users_dupe($cur_user)
	{
		$old_username = $cur_user['username'];
		$suffix = 1;

		// Find new free username
		while (true)
		{
			$username = $old_username.$suffix;
			$result = $this->db->query('SELECT username FROM '.$this->db->prefix.'users WHERE (UPPER(username)=UPPER(\''.$this->db->escape($username).'\') OR UPPER(username)=UPPER(\''.$this->db->escape(ucp_preg_replace('%[^\p{L}\p{N}]%u', '', $username)).'\')) AND id>1') or conv_error('Unable to fetch user info', __FILE__, __LINE__, $this->db->error());

			if (!$this->db->num_rows($result))
				break;
		}

		$_SESSION['converter']['dupe_users'][$cur_user['id']]['username'] = $cur_user['username'] = $username;

		$temp = array();
		foreach ($cur_user as $idx => $value)
			$temp[$idx] = $value === null ? 'NULL' : '\''.$this->db->escape($value).'\'';

		// Insert the renamed user
		$this->db->query('INSERT INTO '.$this->db->prefix.'users('.implode(',', array_keys($temp)).') VALUES ('.implode(',', array_values($temp)).')') or conv_error('Unable to insert data to new table', __FILE__, __LINE__, $this->db->error());

		// Renaming a user also affects a bunch of other stuff, lets fix that too...
		$this->db->query('UPDATE '.$this->db->prefix.'posts SET poster=\''.$this->db->escape($username).'\' WHERE poster_id='.$cur_user['id']) or conv_error('Unable to update posts', __FILE__, __LINE__, $this->db->error());

		// The following must compare using collation utf8_bin otherwise we will accidently update posts/topics/etc belonging to both of the duplicate users, not just the one we renamed!
		$this->db->query('UPDATE '.$this->db->prefix.'posts SET edited_by=\''.$this->db->escape($username).'\' WHERE edited_by=\''.$this->db->escape($old_username).'\' COLLATE utf8_bin') or conv_error('Unable to update posts', __FILE__, __LINE__, $this->db->error());
		$this->db->query('UPDATE '.$this->db->prefix.'topics SET poster=\''.$this->db->escape($username).'\' WHERE poster=\''.$this->db->escape($old_username).'\' COLLATE utf8_bin') or conv_error('Unable to update topics', __FILE__, __LINE__, $this->db->error());
		$this->db->query('UPDATE '.$this->db->prefix.'topics SET last_poster=\''.$this->db->escape($username).'\' WHERE last_poster=\''.$this->db->escape($old_username).'\' COLLATE utf8_bin') or conv_error('Unable to update topics', __FILE__, __LINE__, $this->db->error());
		$this->db->query('UPDATE '.$this->db->prefix.'forums SET last_poster=\''.$this->db->escape($username).'\' WHERE last_poster=\''.$this->db->escape($old_username).'\' COLLATE utf8_bin') or conv_error('Unable to update forums', __FILE__, __LINE__, $this->db->error());
		$this->db->query('UPDATE '.$this->db->prefix.'online SET ident=\''.$this->db->escape($username).'\' WHERE ident=\''.$this->db->escape($old_username).'\' COLLATE utf8_bin') or conv_error('Unable to update online list', __FILE__, __LINE__, $this->db->error());

		// If the user is a moderator or an administrator we have to update the moderator lists
		$result = $this->db->query('SELECT g_moderator FROM '.$this->db->prefix.'groups WHERE g_id='.$cur_user['group_id']) or conv_error('Unable to fetch group', __FILE__, __LINE__, $this->db->error());
		$group_mod = $this->db->result($result);

		if ($cur_user['group_id'] == PUN_ADMIN || $group_mod == '1')
		{
			$result = $this->db->query('SELECT id, moderators FROM '.$this->db->prefix.'forums') or conv_error('Unable to fetch forum list', __FILE__, __LINE__, $this->db->error());

			while ($cur_forum = $this->db->fetch_assoc($result))
			{
				$cur_moderators = ($cur_forum['moderators'] != '') ? unserialize($cur_forum['moderators']) : array();

				if (in_array($cur_user['id'], $cur_moderators))
				{
					unset($cur_moderators[$old_username]);
					$cur_moderators[$username] = $cur_user['id'];
					uksort($cur_moderators, 'utf8_strcasecmp');

					$this->db->query('UPDATE '.$this->db->prefix.'forums SET moderators=\''.$this->db->escape(serialize($cur_moderators)).'\' WHERE id='.$cur_forum['id']) or conv_error('Unable to update forum', __FILE__, __LINE__, $this->db->error());
				}
			}
		}

		$_SESSION['converter']['dupe_users'][$cur_user['id']]['old_username'] = $old_username;
	}


	function sync_db()
	{
		conv_log('Updating post count for each user');
		$start = get_microtime();

		// Update user post count
		$result = $this->db->query_build(array(
			'SELECT'	=> 'poster_id, COUNT(id) AS num_posts',
			'FROM'		=> 'posts',
			'GROUP BY'	=> 'poster_id',
		)) or conv_error('Unable to fetch user posts', __FILE__, __LINE__, $this->db->error());

		while ($cur_user = $this->db->fetch_assoc($result))
		{
			$this->db->query_build(array(
				'UPDATE'	=> 'users',
				'SET'		=> 'num_posts = '.$cur_user['num_posts'],
				'WHERE'		=> 'id = '.$cur_user['poster_id'],
			)) or conv_error('Unable to update user post count', __FILE__, __LINE__, $this->db->error());
		}

		conv_log('Done in '.round(get_microtime() - $start, 6)."\n");
		conv_log('Updating post count for each topic');
		$start = get_microtime();

		// Update post count for each topic
		$result = $this->db->query_build(array(
			'SELECT'	=> 'p.topic_id, COUNT(p.id) AS num_posts',
			'FROM'		=> 'posts AS p',
			'GROUP BY'	=> 'p.topic_id',
		)) or conv_error('Unable to fetch topic posts', __FILE__, __LINE__, $this->db->error());

		while ($cur_topic = $this->db->fetch_assoc($result))
		{
			$this->db->query_build(array(
				'UPDATE'	=> 'topics',
				'SET'		=> 'num_replies = '.($cur_topic['num_posts'] - 1),
				'WHERE'		=> 'id = '.$cur_topic['topic_id'],
			)) or conv_error('Unable to update topic post count', __FILE__, __LINE__, $this->db->error());
		}

		conv_log('Done in '.round(get_microtime() - $start, 6)."\n");
		conv_log('Updating last post for each topic');
		$start = get_microtime();

		// Update last post for each topic
		$subquery = array(
			'SELECT'	=> 'topic_id, MAX(posted) AS last_post',
			'FROM'		=> 'posts',
			'GROUP BY'	=> 'topic_id',
		);

		$result = $this->db->query_build(array(
			'SELECT'	=> 'p.topic_id, p.id, p.posted, p.poster',
			'JOINS'		=> array(
				array(
					'INNER JOIN'=> $this->db->prefix.'posts AS p',
					'ON'		=> 'p.topic_id = t.topic_id AND p.posted = t.last_post',
				)
			),
			'FROM'		=> '('.$this->db->query_build($subquery, true).') AS t',
			'PARAMS'	=> array(
				'NO_PREFIX'		=> true,
			)
		)) or conv_error('Unable to fetch topic last post', __FILE__, __LINE__, $this->db->error());

		while ($cur_topic = $this->db->fetch_assoc($result))
		{
			$this->db->query_build(array(
				'UPDATE'	=> 'topics',
				'SET'		=> 'last_post = '.$cur_topic['posted'].', last_poster = \''.$this->db->escape($cur_topic['poster']).'\', last_post_id = '.$cur_topic['id'],
				'WHERE'		=> 'id = '.$cur_topic['topic_id'],
			)) or conv_error('Unable to update last post for topic', __FILE__, __LINE__, $this->db->error());
		}

		conv_log('Done in '.round(get_microtime() - $start, 6)."\n");
		conv_log('Updating num topics and num posts for each forum');
		$start = get_microtime();

		// Update num_topics and num_posts for each forum
		$result = $this->db->query_build(array(
			'SELECT'	=> 'forum_id, COUNT(id) AS num_topics, SUM(num_replies) + COUNT(id) AS num_posts',
			'FROM'		=> 'topics',
			'GROUP BY'	=> 'forum_id',
		)) or conv_error('Unable to fetch topics for forum', __FILE__, __LINE__, $this->db->error());

		while ($cur_forum = $this->db->fetch_assoc($result))
		{
			$this->db->query_build(array(
				'UPDATE'	=> 'forums',
				'SET'		=> 'num_topics = '.$cur_forum['num_topics'].', num_posts = '.$cur_forum['num_posts'],
				'WHERE'		=> 'id = '.$cur_forum['forum_id'],
			)) or conv_error('Unable to update topic count for forum', __FILE__, __LINE__, $this->db->error());
		}

		conv_log('Done in '.round(get_microtime() - $start, 6)."\n");
		conv_log('Updating last post for each forum');
		$start = get_microtime();

		// Update last post for each forum
		$subquery = array(
			'SELECT'	=> 'forum_id, MAX(last_post) AS last_post',
			'FROM'		=> 'topics',
			'GROUP BY'	=> 'forum_id',
		);

		$result = $this->db->query_build(array(
			'SELECT'	=> 't.forum_id, t.last_post_id, t.last_post, t.last_poster',
			'JOINS'		=> array(
				array(
					'INNER JOIN'=> $this->db->prefix.'topics AS t',
					'ON'		=> 't.forum_id = f.forum_id AND f.last_post = t.last_post',
				)
			),
			'FROM'		=> '('.$this->db->query_build($subquery, true).') AS f',
			'PARAMS'	=> array(
				'NO_PREFIX'		=> true,
			)
		)) or conv_error('Unable to fetch forum last post', __FILE__, __LINE__, $this->db->error());

		while ($cur_forum = $this->db->fetch_assoc($result))
		{
			$this->db->query_build(array(
				'UPDATE'	=> 'forums',
				'SET'		=> 'last_post = '.$cur_forum['last_post'].', last_poster = \''.$this->db->escape($cur_forum['last_poster']).'\', last_post_id = '.$cur_forum['last_post_id'],
				'WHERE'		=> 'id = '.$cur_forum['forum_id'],
			)) or conv_error('Unable to update last post for forum', __FILE__, __LINE__, $this->db->error());
		}

		conv_log('Done in '.round(get_microtime() - $start, 6)."\n");
	}
}
