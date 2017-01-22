<?php

/**
 * @author    Jens Krumsieck
 * @copyright 2014-2015 codequake.de
 * @license   LGPL
 */
namespace cms\system\exporter;

use wcf\data\category\Category;
use wcf\data\category\CategoryEditor;
use wcf\data\package\PackageCache;
use wcf\system\database\util\PreparedStatementConditionBuilder;
use wcf\system\exception\SystemException;
use wcf\system\importer\ImportHandler;
use wcf\system\language\LanguageFactory;
use wcf\system\WCF;

/**
 * Exporter for Fireball's news system (version 1.x)
 */
class Fireball1NewsExporter extends AbstractFireballNewsExporter {
	/**
	 * @inheritDoc
	 */
	public function validateFileAccess() {
		/* if (in_array('de.codequake.cms.news', $this->selectedData)) {
			if (empty($this->fileSystemPath) || !@file_exists($this->fileSystemPath.'lib/core.functions.php')) {
				return false;
			}
		} */

		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function getSupportedData() {
		return array(
			'de.codequake.cms.category.news' => array('de.codequake.cms.category.news.acl',),
			'de.codequake.cms.news' => array(
				'de.codequake.cms.news.comment',
				'de.codequake.cms.news.like',
				'de.codequake.cms.news.attachment',
			),
		);
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
		$statement->execute(array('de.codequake.cms'));
		$row = $statement->fetchArray();

		if ($row !== false) {
			// check cms version
			if (substr($row['packageVersion'], 0, 1) != 1) {
				throw new SystemException('Cannot find Fireball CMS 1.x installation', $this->database);
			}
		}
		else {
			throw new SystemException('Cannot find Fireball CMS installation', $this->database);
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getQueue() {
		$queue = array();

		// category
		if (in_array('de.codequake.cms.category.news', $this->selectedData)) {
			$queue[] = 'de.codequake.cms.category.news';

			if (in_array('de.codequake.cms.category.news.acl', $this->selectedData)) {
				$queue[] = 'de.codequake.cms.category.news.acl';
			}
		}

		// news
		if (in_array('de.codequake.cms.news', $this->selectedData)) {
			$queue[] = 'de.codequake.cms.news';

			if (in_array('de.codequake.cms.news.comment', $this->selectedData)) {
				$queue[] = 'de.codequake.cms.news.comment';
				$queue[] = 'de.codequake.cms.news.comment.response';
			}

			if (in_array('de.codequake.cms.news.like', $this->selectedData)) {
				$queue[] = 'de.codequake.cms.news.like';
			}

			if (in_array('de.codequake.cms.news.attachment', $this->selectedData)) {
				$queue[] = 'de.codequake.cms.news.attachment';
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

		$sql = '
            SELECT *
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
	 * Exports ACLs.
	 *
	 * @param int $offset
	 * @param int $limit
	 * @throws SystemException
	 * @throws \wcf\system\database\exception\DatabaseQueryException
	 * @throws \wcf\system\database\exception\DatabaseQueryExecutionException
	 */
	public function exportNewsCategoryACLs($offset, $limit) {
		$acls = $this->getCategoryACLs($offset, $limit);

		foreach ($acls as $data) {
			$optionName = $data['optionName'];
			unset($data['optionName']);

			ImportHandler::getInstance()->getImporter('de.codequake.cms.category.news.acl')->import(0, $data,
				array('optionName' => $optionName,));
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
		$sql = '
            SELECT COUNT(*) AS count
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

			$sql = 'SELECT	*
				FROM	cms' . $this->dbNo . '_news_to_category
				WHERE 	newsID = ?';
			$statement2 = $this->database->prepareStatement($sql);
			$statement2->execute(array($row['newsID']));
			while ($assignment = $statement2->fetchArray()) {
				// categories
				$additionalData['categories'][] = $assignment['categoryID'];
			}

			ImportHandler::getInstance()->getImporter('de.codequake.cms.news')->import($row['newsID'], array(
				'userID' => ($row['userID'] ? : null),
				'username' => ($row['username'] ? : ''),
				'subject' => $row['subject'],
				'message' => $row['message'],
				'time' => $row['time'],
				'comments' => $row['comments'],
				'enableSmilies' => $row['enableSmilies'],
				'enableHtml' => $row['enableHtml'],
				'enableBBCodes' => $row['enableBBCodes'],
				'isDisabled' => $row['isDisabled'],
				'isDeleted' => $row['isDeleted'],
				'ipAddress' => $row['ipAddress'],
				'cumulativeLikes' => $row['cumulativeLikes'],
			), $additionalData);

			if ($row['languageCode']) {
				$additionalData['languageCode'] = $row['languageCode'];
			}

			if (isset($tags[$row['newsID']])) {
				$additionalData['tags'] = $tags[$row['newsID']];
			}
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
		$objectTypeID = $this->getObjectTypeID('com.woltlab.wcf.comment.commentableContent',
			'de.codequake.cms.news.comment');

		$sql = '
            SELECT COUNT(*) AS count
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
		$objectTypeID = $this->getObjectTypeID('com.woltlab.wcf.comment.commentableContent',
			'de.codequake.cms.news.comment');

		$sql = '
            SELECT *
			FROM wcf' . $this->dbNo . '_comment
			WHERE objectTypeID = ?
			ORDER BY commentID';
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array($objectTypeID));

		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('de.codequake.cms.news.comment')->import($row['commentID'], array(
				'objectID' => $row['objectID'],
				'userID' => $row['userID'],
				'username' => $row['username'],
				'message' => $row['message'],
				'time' => $row['time'],
				'objectTypeID' => $objectTypeID,
				'responses' => 0,
				'responseIDs' => serialize(array()),
			));
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
		$objectTypeID = $this->getObjectTypeID('com.woltlab.wcf.comment.commentableContent',
			'de.codequake.cms.news.comment');

		$sql = '
            SELECT COUNT(*) AS count
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
		$objectTypeID = $this->getObjectTypeID('com.woltlab.wcf.comment.commentableContent',
			'de.codequake.cms.news.comment');

		$sql = '
            SELECT *
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
			ImportHandler::getInstance()->getImporter('de.codequake.cms.news.comment.response')->import($row['responseID'],
				array(
					'commentID' => $row['commentID'],
					'time' => $row['time'],
					'userID' => $row['userID'],
					'username' => $row['username'],
					'message' => $row['message'],
				));
		}
	}

	/**
	 * @return int
	 * @throws \wcf\system\database\exception\DatabaseQueryException
	 * @throws \wcf\system\database\exception\DatabaseQueryExecutionException
	 */
	public function countNewsAttachments() {
		$objectTypeID = $this->getObjectTypeID('com.woltlab.wcf.attachment.objectType', 'de.codequake.cms.news');

		$sql = '
            SELECT COUNT(*) AS count
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

		$sql = '
            SELECT *
            FROM wcf' . $this->dbNo . '_attachment
            WHERE objectTypeID = ? AND objectID IS NOT NULL
            ORDER BY attachmentID';
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array($objectTypeID));

		while ($row = $statement->fetchArray()) {
			$fileLocation = $this->fileSystemPath . 'attachments/' . substr($row['fileHash'], 0,
					2) . '/' . $row['attachmentID'] . '-' . $row['fileHash'];

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
				), array('fileLocation' => $fileLocation));
		}
	}

	/**
	 * Counts likes.
	 *
	 * @return int
	 * @throws \wcf\system\database\exception\DatabaseQueryException
	 * @throws \wcf\system\database\exception\DatabaseQueryExecutionException
	 */
	public function countNewsLikes() {
		$objectTypeID = $this->getObjectTypeID('com.woltlab.wcf.like.likeableObject', 'de.codequake.cms.likeableNews');

		$sql = '
            SELECT COUNT(*) AS count
            FROM wcf' . $this->dbNo . '_like
            WHERE objectTypeID = ?';
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array($objectTypeID));
		$row = $statement->fetchArray();

		return $row['count'];
	}

	/**
	 * Exports likes.
	 *
	 * @param int $offset
	 * @param int $limit
	 * @throws SystemException
	 * @throws \wcf\system\database\exception\DatabaseQueryException
	 * @throws \wcf\system\database\exception\DatabaseQueryExecutionException
	 */
	public function exportNewsLikes($offset, $limit) {
		$objectTypeID = $this->getObjectTypeID('com.woltlab.wcf.like.likeableObject', 'de.codequake.cms.likeableNews');

		$sql = '
            SELECT *
            FROM wcf' . $this->dbNo . '_like
            WHERE objectTypeID = ?
            ORDER BY likeID';
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array($objectTypeID));

		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('de.codequake.cms.news.like')->import(0, array(
				'objectID' => $row['objectID'],
				'objectUserID' => $row['objectUserID'],
				'userID' => $row['userID'],
				'likeValue' => $row['likeValue'],
				'time' => $row['time'],
			));
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
		$sql = '
            SELECT objectTypeID
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
	 * Returns ACLs.
	 *
	 * @param int $offset
	 * @param int $limit
	 * @return array
	 * @throws \wcf\system\database\exception\DatabaseQueryException
	 * @throws \wcf\system\database\exception\DatabaseQueryExecutionException
	 */
	protected function getCategoryACLs($offset, $limit) {
		$objectTypeID = $this->getObjectTypeID('com.woltlab.wcf.acl', 'de.codequake.cms.category.news');

		$sql = '(
				SELECT		acl_option.optionName, acl_option.optionID,
						option_to_group.objectID, option_to_group.optionValue, 0 AS userID, option_to_group.groupID
				FROM		wcf' . $this->dbNo . '_acl_option_to_group option_to_group
				LEFT JOIN	wcf' . $this->dbNo . '_acl_option acl_option
				ON		(acl_option.optionID = option_to_group.optionID)
				WHERE		acl_option.objectTypeID = ?
			)
			UNION
			(
				SELECT		acl_option.optionName, acl_option.optionID,
						option_to_user.objectID, option_to_user.optionValue, option_to_user.userID, 0 AS groupID
				FROM		wcf' . $this->dbNo . '_acl_option_to_user option_to_user
				LEFT JOIN	wcf' . $this->dbNo . '_acl_option acl_option
				ON		(acl_option.optionID = option_to_user.optionID)
				WHERE		acl_option.objectTypeID = ?
			)
			ORDER BY	optionID, objectID, groupID, userID';
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array(
			$objectTypeID,
			$objectTypeID
		));

		$acls = array();
		while ($row = $statement->fetchArray()) {
			$data = array(
				'objectID' => $row['objectID'],
				'optionName' => $row['optionName'],
				'optionValue' => $row['optionValue'],
			);

			if ($row['userID']) {
				$data['userID'] = $row['userID'];
			}
			if ($row['groupID']) {
				$data['groupID'] = $row['groupID'];
			}

			$acls[] = $data;
		}

		return $acls;
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
			$categoryID = ImportHandler::getInstance()->getImporter('de.codequake.cms.category.news')->import($category['categoryID'],
				array(
					'parentCategoryID' => $category['parentCategoryID'],
					'title' => $category['title'],
					'description' => $category['description'],
					'showOrder' => $category['showOrder'],
					'time' => $category['time'],
					'isDisabled' => $category['isDisabled'],
					'additionalData' => serialize(array()),
				));

			$this->updateCategoryI18nData($categoryID, $category);

			$this->exportCategoriesRecursively($category['categoryID']);
		}
	}

	/**
	 * Counts ACLs.
	 */
	protected function countNewsCategoryACLs() {
		$objectTypeID = $this->getObjectTypeID('com.woltlab.wcf.acl', 'de.codequake.cms.category.news');

		$sql = '
            SELECT (
                (
                    SELECT COUNT(*)
                    FROM wcf' . $this->dbNo . '_acl_option_to_group option_to_group
                    LEFT JOIN wcf' . $this->dbNo . '_acl_option acl_option ON (acl_option.optionID = option_to_group.optionID)
                    WHERE acl_option.objectTypeID = ?
                ) + (
                    SELECT COUNT(*)
                    FROM wcf' . $this->dbNo . '_acl_option_to_user option_to_user
                    LEFT JOIN wcf' . $this->dbNo . '_acl_option acl_option ON (acl_option.optionID = option_to_user.optionID)
                    WHERE acl_option.objectTypeID = ?
                )
            ) AS count';
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array(
			$objectTypeID,
			$objectTypeID
		));
		$row = $statement->fetchArray();

		return $row['count'];
	}

	/**
	 * Returns a list of tags.
	 *
	 * @param int[] $newsIDs
	 * @return array
	 * @throws \wcf\system\database\exception\DatabaseQueryException
	 * @throws \wcf\system\database\exception\DatabaseQueryExecutionException
	 */
	protected function getTags(array $newsIDs) {
		$tags = array();
		$objectTypeID = $this->getObjectTypeID('com.woltlab.wcf.tagging.taggableObject', 'de.codequake.cms.news');

		// prepare conditions
		$conditionBuilder = new PreparedStatementConditionBuilder();
		$conditionBuilder->add('tag_to_object.objectTypeID = ?', array($objectTypeID));
		$conditionBuilder->add('tag_to_object.objectID IN (?)', array($newsIDs));

		// read tags
		$sql = '
            SELECT tag.name, tag_to_object.objectID
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
