<?php

namespace cms\system\exporter;

use wcf\system\database\util\PreparedStatementConditionBuilder;
use wcf\system\importer\ImportHandler;

class Fireball2NewsToArticleExporter extends AbstractFireballNewsExporter {
	/**
	 * @inheritDoc
	 */
	protected $methods = array(
		'com.woltlab.wcf.article.category' => 'NewsCategories',
		'com.woltlab.wcf.article' => 'NewsEntries',
		'com.woltlab.wcf.article.comment' => 'NewsComments',
		'com.woltlab.wcf.article.comment.response' => 'NewsCommentResponses'
	);

	/**
	 * @inheritDoc
	 */
	protected $limits = array(
		'com.woltlab.wcf.article' => 300
	);

	/**
	 * @inheritDoc
	 */
	public function validateFileAccess() {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function validateDatabaseAccess() {
		parent::validateDatabaseAccess();

		$sql = 'SELECT	packageID, packageDir, packageVersion
			FROM	wcf' . $this->dbNo . '_package
			WHERE	package = ?';
		$statement = $this->database->prepareStatement($sql, 1);
		$statement->execute(array('de.codequake.cms.news'));
		$row = $statement->fetchArray();

		if ($row !== false) {
			if (substr($row['packageVersion'], 0, 3) != '1.2' && substr($row['packageVersion'], 0, 3) != '2.0') {
				throw new SystemException('Cannot find Fireball CMS News 1.2/2.0 installation', $this->database);
			}
		}
		else {
			throw new SystemException('Cannot find Fireball CMS News installation', $this->database);
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getSupportedData() {
		return array(
			'com.woltlab.wcf.article.category' => array(),
			'com.woltlab.wcf.article' => array(
				'com.woltlab.wcf.article.comment'
			),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function getQueue() {
		$queue = array();

		// category
		if (in_array('com.woltlab.wcf.article.category', $this->selectedData)) {
			$queue[] = 'com.woltlab.wcf.article.category';
		}

		// news
		if (in_array('com.woltlab.wcf.article', $this->selectedData)) {
			$queue[] = 'com.woltlab.wcf.article';

			if (in_array('com.woltlab.wcf.article.comment', $this->selectedData)) {
				$queue[] = 'com.woltlab.wcf.article.comment';
				$queue[] = 'com.woltlab.wcf.article.comment.response';
			}
		}

		return $queue;
	}

	/**
	 * Counts categories.
	 *
	 * @return int
	 * @throws \wcf\system\database\exception\DatabaseQueryException
	 * @throws \wcf\system\database\exception\DatabaseQueryExecutionException
	 */
	public function countNewsCategories() {
		$objectTypeID = $this->getObjectTypeID('com.woltlab.wcf.category', 'de.codequake.cms.category.news');

		$sql = 'SELECT	COUNT(*) AS count
			FROM	wcf' . $this->dbNo . '_category
			WHERE	objectTypeID = ?';
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array($objectTypeID));
		$row = $statement->fetchArray();

		return $row['count'];
	}

	/**
	 * Exports categories.
	 *
	 * @param int $offset
	 * @param int $limit
	 * @throws SystemException
	 * @throws \wcf\system\database\exception\DatabaseQueryException
	 * @throws \wcf\system\database\exception\DatabaseQueryExecutionException
	 */
	public function exportNewsCategories($offset, $limit) {
		$objectTypeID = $this->getObjectTypeID('com.woltlab.wcf.category', 'de.codequake.cms.category.news');

		$sql = 'SELECT *
			FROM wcf' . $this->dbNo . '_category
			WHERE objectTypeID = ?
			ORDER BY parentCategoryID, showOrder, categoryID';
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array($objectTypeID));

		while ($row = $statement->fetchArray()) {
			$this->categoryCache[$row['parentCategoryID']][] = $row;
		}

		$this->exportCategoriesRecursively();
	}

	/**
	 * Counts blog entries.
	 *
	 * @return int
	 * @throws \wcf\system\database\exception\DatabaseQueryException
	 * @throws \wcf\system\database\exception\DatabaseQueryExecutionException
	 */
	public function countNewsEntries() {
		$sql = 'SELECT COUNT(*) AS count
			FROM cms' . $this->dbNo . '_news';
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
	 * @throws SystemException
	 * @throws \wcf\system\database\exception\DatabaseQueryException
	 * @throws \wcf\system\database\exception\DatabaseQueryExecutionException
	 */
	public function exportNewsEntries($offset, $limit) {
		$newsIDs = array();
		$sql = 'SELECT	*
			FROM	cms' . $this->dbNo . '_news
			ORDER BY	newsID';
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$newsIDs[] = $row['newsID'];
		}

		$tags = $this->getTags($newsIDs);

		// get the news
		$sql = 'SELECT		news.*, language.languageCode
			FROM		cms' . $this->dbNo . '_news news
			LEFT JOIN	wcf' . $this->dbNo . '_language language
			ON		(language.languageID = news.languageID)';
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$additionalData = array();

			$additionalData['contents'] = [
				!empty($row['languageCode']) ? $row['languageCode'] : 0 => [
					'title' => $row['subject'],
					'teaser' => $row['teaser'],
					'content' => $row['message'],
					'imageID' => $row['imageID'],
					'tags' => isset($tags[$row['newsID']]) ? $tags[$row['newsID']] : []
				]
			];

			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.article')->import($row['newsID'],
				array(
					'userID' => ($row['userID'] ? : null),
					'username' => ($row['username'] ? : ''),
					'time' => $row['time'],
					'comments' => $row['comments'],
					'cumulativeLikes' => $row['cumulativeLikes']
				), $additionalData
			);
		}
	}

	/**
	 * Counts news comments.
	 *
	 * @return int
	 * @throws \wcf\system\database\exception\DatabaseQueryException
	 * @throws \wcf\system\database\exception\DatabaseQueryExecutionException
	 */
	public function countNewsComments() {
		$objectTypeID = $this->getObjectTypeID('com.woltlab.wcf.comment.commentableContent', 'de.codequake.cms.news.comment');

		$sql = 'SELECT COUNT(*) AS count
			FROM wcf' . $this->dbNo . '_comment
			WHERE objectTypeID = ?';
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array($objectTypeID));
		$row = $statement->fetchArray();

		return $row['count'];
	}

	/**
	 * Exports news comments.
	 *
	 * @param int $offset
	 * @param int $limit
	 * @throws SystemException
	 * @throws \wcf\system\database\exception\DatabaseQueryException
	 * @throws \wcf\system\database\exception\DatabaseQueryExecutionException
	 */
	public function exportNewsComments($offset, $limit) {
		$objectTypeID = $this->getObjectTypeID('com.woltlab.wcf.comment.commentableContent', 'de.codequake.cms.news.comment');

		$sql = 'SELECT *
			FROM wcf' . $this->dbNo . '_comment
			WHERE objectTypeID = ?
			ORDER BY commentID';
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array($objectTypeID));

		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.article.comment')->import($row['commentID'],
				array(
					'objectID' => $row['objectID'],
					'userID' => $row['userID'],
					'username' => $row['username'],
					'message' => $row['message'],
					'time' => $row['time'],
					'objectTypeID' => $objectTypeID,
					'responses' => 0,
					'responseIDs' => serialize(array()),
				)
			);
		}
	}

	/**
	 * Counts news comment responses.
	 *
	 * @return int
	 * @throws \wcf\system\database\exception\DatabaseQueryException
	 * @throws \wcf\system\database\exception\DatabaseQueryExecutionException
	 */
	public function countNewsCommentResponses() {
		$objectTypeID = $this->getObjectTypeID('com.woltlab.wcf.comment.commentableContent', 'de.codequake.cms.news.comment');

		$sql = 'SELECT COUNT(*) AS count
			FROM wcf' . $this->dbNo . '_comment_response
			WHERE commentID IN (
				SELECT commentID
				FROM wcf' . $this->dbNo . '_comment
				WHERE	objectTypeID = ?
			)';
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array($objectTypeID));
		$row = $statement->fetchArray();

		return $row['count'];
	}

	/**
	 * Exports news comment responses.
	 *
	 * @param int $offset
	 * @param int $limit
	 * @throws SystemException
	 * @throws \wcf\system\database\exception\DatabaseQueryException
	 * @throws \wcf\system\database\exception\DatabaseQueryExecutionException
	 */
	public function exportNewsCommentResponses($offset, $limit) {
		$objectTypeID = $this->getObjectTypeID('com.woltlab.wcf.comment.commentableContent', 'de.codequake.cms.news.comment');

		$sql = 'SELECT *
			FROM wcf' . $this->dbNo . '_comment_response
			WHERE commentID IN (
				SELECT commentID
				FROM wcf' . $this->dbNo . '_comment
				WHERE objectTypeID = ?
			)
			ORDER BY responseID';
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array($objectTypeID));

		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.article.comment.response')->import($row['responseID'],
				array(
					'commentID' => $row['commentID'],
					'time' => $row['time'],
					'userID' => $row['userID'],
					'username' => $row['username'],
					'message' => $row['message'],
				)
			);
		}
	}

	/**
	 * @return int
	 * @throws \wcf\system\database\exception\DatabaseQueryException
	 * @throws \wcf\system\database\exception\DatabaseQueryExecutionException
	 */
	public function countNewsAttachments() {
		$objectTypeID = $this->getObjectTypeID('com.woltlab.wcf.attachment.objectType', 'de.codequake.cms.news');

		$sql = 'SELECT COUNT(*) AS count
			FROM wcf' . $this->dbNo . '_attachment
			WHERE objectTypeID = ? AND objectID IS NOT NULL';
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array($objectTypeID));
		$row = $statement->fetchArray();

		return $row['count'];
	}

	/**
	 * @param int $offset
	 * @param int $limit
	 * @throws SystemException
	 * @throws \wcf\system\database\exception\DatabaseQueryException
	 * @throws \wcf\system\database\exception\DatabaseQueryExecutionException
	 */
	public function exportNewsAttachments($offset, $limit) {
		$objectTypeID = $this->getObjectTypeID('com.woltlab.wcf.attachment.objectType', 'de.codequake.cms.news');

		$sql = 'SELECT *
			FROM wcf' . $this->dbNo . '_attachment
			WHERE objectTypeID = ? AND objectID IS NOT NULL
			ORDER BY attachmentID';
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array($objectTypeID));

		while ($row = $statement->fetchArray()) {
			$fileLocation = $this->fileSystemPath . 'attachments/' . substr($row['fileHash'], 0, 2) . '/' . $row['attachmentID'] . '-' . $row['fileHash'];

			ImportHandler::getInstance()->getImporter('de.codequake.cms.news.attachment')->import($row['attachmentID'],
				array(
					'objectID' => $row['objectID'],
					'userID' => ($row['userID'] ? : null),
					'filename' => $row['filename'],
					'filesize' => $row['filesize'],
					'fileType' => $row['fileType'],
					'fileHash' => $row['fileHash'],
					'isImage' => $row['isImage'],
					'width' => $row['width'],
					'height' => $row['height'],
					'downloads' => $row['downloads'],
					'lastDownloadTime' => $row['lastDownloadTime'],
					'uploadTime' => $row['uploadTime'],
					'showOrder' => $row['showOrder'],
				), array('fileLocation' => $fileLocation)
			);
		}
	}

	/**
	 * Returns the id of the object type with the given name.
	 *
	 * @param string $definitionName
	 * @param string $objectTypeName
	 * @return integer
	 * @throws \wcf\system\database\exception\DatabaseQueryException
	 * @throws \wcf\system\database\exception\DatabaseQueryExecutionException
	 */
	protected function getObjectTypeID($definitionName, $objectTypeName) {
		$sql = 'SELECT objectTypeID
			FROM wcf' . $this->dbNo . '_object_type
			WHERE objectType = ? AND definitionID = (
				SELECT definitionID
				FROM wcf' . $this->dbNo . '_object_type_definition
				WHERE definitionName = ?
			)';
		$statement = $this->database->prepareStatement($sql, 1);
		$statement->execute(array(
			$objectTypeName,
			$definitionName
		));
		$row = $statement->fetchArray();

		if ($row !== false) {
			return $row['objectTypeID'];
		}

		return 0;
	}

	/**
	 * Exports the categories of the given parent recursively.
	 *
	 * @param int $parentID
	 * @throws SystemException
	 */
	protected function exportCategoriesRecursively($parentID = 0) {
		if (!isset($this->categoryCache[$parentID])) {
			return;
		}

		foreach ($this->categoryCache[$parentID] as $category) {
			// import category
			$categoryID = ImportHandler::getInstance()->getImporter('com.woltlab.wcf.article.category')->import($category['categoryID'],
				array(
					'parentCategoryID' => $category['parentCategoryID'],
					'title' => $category['title'],
					'description' => $category['description'],
					'showOrder' => $category['showOrder'],
					'time' => $category['time'],
					'isDisabled' => $category['isDisabled'],
					'additionalData' => serialize(array()),
				)
			);

			$this->updateCategoryI18nData($categoryID, $category);

			$this->exportCategoriesRecursively($category['categoryID']);
		}
	}

	/**
	 * Returns a list of tags.
	 *
	 * @param int[] $newsIDs
	 * @return array
	 * @throws \wcf\system\database\exception\DatabaseQueryException
	 * @throws \wcf\system\database\exception\DatabaseQueryExecutionException
	 */
	private function getTags(array $newsIDs) {
		$tags = array();
		$objectTypeID = $this->getObjectTypeID('com.woltlab.wcf.tagging.taggableObject', 'de.codequake.cms.news');

		// prepare conditions
		$conditionBuilder = new PreparedStatementConditionBuilder();
		$conditionBuilder->add('tag_to_object.objectTypeID = ?', array($objectTypeID));
		$conditionBuilder->add('tag_to_object.objectID IN (?)', array($newsIDs));

		// read tags
		$sql = 'SELECT tag.name, tag_to_object.objectID
			FROM wcf' . $this->dbNo . '_tag_to_object tag_to_object
			LEFT JOIN wcf' . $this->dbNo . '_tag tag ON (tag.tagID = tag_to_object.tagID)
			' . $conditionBuilder;
		$statement = $this->database->prepareStatement($sql);
		$statement->execute($conditionBuilder->getParameters());

		while ($row = $statement->fetchArray()) {
			if (!isset($tags[$row['objectID']])) {
				$tags[$row['objectID']] = array();
			}

			$tags[$row['objectID']][] = $row['name'];
		}

		return $tags;
	}
}
