<?php

namespace cms\system\exporter;

use wcf\system\exporter\AbstractExporter;
use wcf\system\importer\ImportHandler;
use wcf\util\StringUtil;

/**
 * Exporter to export news from cnews (WCF 1.1).
 */
class Cnews1xToNewsExporter extends AbstractExporter {
	/**
	 * @inheritDoc
	 */
	protected $methods = array(
		'de.codequake.cms.category.news' => 'NewsCategories',
		'de.codequake.cms.news' => 'NewsEntries',
		'de.codequake.cms.news.comment' => 'NewsComments',
	);

	protected $dbNo = 1;

	/**
	 * category cache.
	 *
	 * @var array
	 */
	protected $categoryCache = array();

	/**
	 * @inheritDoc
	 */
	public function init() {
		parent::init();

		if (preg_match('/^wcf(\d+)_$/', $this->databasePrefix, $match)) {
			$this->dbNo = $match[1];
		}

		// file system path
		if (!empty($this->fileSystemPath)) {
			if (!@file_exists($this->fileSystemPath . 'lib/core.functions.php') && @file_exists($this->fileSystemPath . 'wcf/lib/core.functions.php')) {
				$this->fileSystemPath = $this->fileSystemPath . 'wcf/';
			}
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getSupportedData() {
		return array(
			'de.codequake.cms.news' => array(
				'de.codequake.cms.category.news',
				'de.codequake.cms.news.comment',
			),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function getQueue() {
		$queue = array();

		// news
		if (in_array('de.codequake.cms.news', $this->selectedData)) {
			if (in_array('de.codequake.cms.category.news', $this->selectedData)) {
				$queue[] = 'de.codequake.cms.category.news';
			}
			$queue[] = 'de.codequake.cms.news';
			if (in_array('de.codequake.cms.news.comment', $this->selectedData)) {
				$queue[] = 'de.codequake.cms.news.comment';
			}
		}

		return $queue;
	}

	/**
	 * @inheritDoc
	 */
	public function validateDatabaseAccess() {
		parent::validateDatabaseAccess();

		$sql = 'SELECT	COUNT(*)
			FROM	wcf' . $this->dbNo . '_cnews_news';
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
	}

	/**
	 * @inheritDoc
	 */
	public function validateFileAccess() {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function getDefaultDatabasePrefix() {
		return 'wbb1_1_';
	}

	/**
	 * Counts categories.
	 *
	 * @return int
	 * @throws \wcf\system\database\exception\DatabaseQueryException
	 * @throws \wcf\system\database\exception\DatabaseQueryExecutionException
	 */
	public function countNewsCategories() {
		$sql = 'SELECT	COUNT(*) AS count
			FROM	wcf' . $this->dbNo . '_cnews_category';
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();

		return $row['count'];
	}

	/**
	 * Exports categories.
	 *
	 * @param int $offset
	 * @param int $limit
	 * @throws \wcf\system\database\exception\DatabaseQueryException
	 * @throws \wcf\system\database\exception\DatabaseQueryExecutionException
	 * @throws \wcf\system\exception\SystemException
	 */
	public function exportNewsCategories($offset, $limit) {
		$sql = '
            SELECT *
			FROM wcf' . $this->dbNo . '_cnews_category
			ORDER BY categoryID';
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();

		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('de.codequake.cms.category.news')->import($row['categoryID'],
				array(
					'title' => $row['title'],
					'parentCategoryID' => $row['parentID'],
					'time' => TIME_NOW,
					'isDisabled' => 0,
					'showOrder' => 0,
				));
		}
	}

	/**
	 * Counts blog entries.
	 *
	 * @return int
	 * @throws \wcf\system\database\exception\DatabaseQueryException
	 * @throws \wcf\system\database\exception\DatabaseQueryExecutionException
	 */
	public function countNewsEntries() {
		$sql = 'SELECT	COUNT(*) AS count
			FROM	wcf' . $this->dbNo . '_cnews_news';
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();

		return $row['count'];
	}

	/**
	 * Exports blog entries.
	 *
	 * @param int $offset
	 * @param int $limit
	 * @throws \wcf\system\database\exception\DatabaseQueryException
	 * @throws \wcf\system\database\exception\DatabaseQueryExecutionException
	 * @throws \wcf\system\exception\SystemException
	 */
	public function exportNewsEntries($offset, $limit) {
		$sql = '
            SELECT *
			FROM wcf' . $this->dbNo . '_cnews_news
			ORDER BY newsID';
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();

		while ($row = $statement->fetchArray()) {
			$newsIDs[] = $row['newsID'];
		}

		// get the news
		$sql = '
            SELECT *
			FROM wcf' . $this->dbNo . '_cnews_news';
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();

		while ($row = $statement->fetchArray()) {
			$additionalData = array();

			// categories
			$additionalData['categories'][] = $row['categoryID'];

			ImportHandler::getInstance()->getImporter('de.codequake.cms.news')->import($row['newsID'], array(
				'userID' => ($row['userID'] ? : null),
				'username' => ($row['username'] ? : ''),
				'subject' => $row['topic'],
				'message' => self::fixMessage($row['content']),
				'time' => $row['time'],
				'comments' => 0,
				'enableSmilies' => 0,
				'enableHtml' => 1,
				'enableBBCodes' => 0,
				'isDisabled' => ($row['enable'] ? 0 : 1),
				'isDeleted' => 0,
			), $additionalData);
		}
	}

	/**
	 * Counts blog comments.
	 *
	 * @return int
	 * @throws \wcf\system\database\exception\DatabaseQueryException
	 * @throws \wcf\system\database\exception\DatabaseQueryExecutionException
	 */
	public function countNewsComments() {
		$sql = '
            SELECT COUNT(*) AS count
			FROM wcf' . $this->dbNo . '_cnews_comment';
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();

		return $row['count'];
	}

	/**
	 * Exports blog comments.
	 *
	 * @param int $offset
	 * @param int $limit
	 * @throws \wcf\system\database\exception\DatabaseQueryException
	 * @throws \wcf\system\database\exception\DatabaseQueryExecutionException
	 * @throws \wcf\system\exception\SystemException
	 */
	public function exportNewsComments($offset, $limit) {
		$sql = '
            SELECT *
			FROM wcf' . $this->dbNo . '_cnews_comment
			ORDER BY commentID';
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();

		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('de.codequake.cms.news.comment.response')->import($row['commentID'],
				array(
					'commentID' => $row['commentID'],
					'userID' => $row['userID'],
					'username' => $row['username'],
					'message' => $row['content'],
					'time' => $row['time'],
				));
		}
	}

	/**
	 * @param string $string
	 *
	 * @return string
	 */
	private static function fixMessage($string) {
		$string = str_replace("\n", "<br />\n", StringUtil::unifyNewlines($string));

		return $string;
	}
}
