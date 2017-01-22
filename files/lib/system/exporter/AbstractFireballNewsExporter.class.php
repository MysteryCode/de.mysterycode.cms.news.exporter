<?php

namespace cms\system\exporter;

use wcf\system\exporter\AbstractExporter;

abstract class AbstractFireballNewsExporter extends AbstractExporter {
	/**
	 * wcf installation number.
	 *
	 * @var int
	 */
	protected $dbNo = 0;

	/**
	 * @var array
	 */
	protected $categoryCache = array();

	/**
	 * @inheritDoc
	 */
	protected $methods = array(
		'de.codequake.cms.category.news' => 'NewsCategories',
		'de.codequake.cms.category.news.acl' => 'NewsCategoryACLs',
		'de.codequake.cms.news' => 'NewsEntries',
		'de.codequake.cms.news.comment' => 'NewsComments',
		'de.codequake.cms.news.comment.response' => 'NewsCommentResponses',
		'de.codequake.cms.news.like' => 'NewsLikes',
		'de.codequake.cms.news.attachment' => 'NewsAttachments'
	);

	/**
	 * @inheritDoc
	 */
	protected $limits = array(
		'de.codequake.cms.category.news' => 300,
		'de.codequake.cms.category.news.acl' => 50,
		'de.codequake.cms.news' => 200,
		'de.codequake.cms.attachment' => 100
	);

	/**
	 * @inheritDoc
	 */
	public function getDefaultDatabasePrefix() {
		return 'cms1_';
	}

	/**
	 * regex for getting database number
	 * @var string
	 */
	protected $databasePrefixRegex = '/^cms(\d+)_$/';

	/**
	 * @inheritDoc
	 */
	public function init() {
		parent::init();

		if (preg_match($this->databasePrefixRegex, $this->databasePrefix, $match)) {
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
	 * Returns the values of the language item with the given name.
	 *
	 * @param string $languageItem
	 * @return array
	 * @throws \wcf\system\database\exception\DatabaseQueryException
	 * @throws \wcf\system\database\exception\DatabaseQueryExecutionException
	 */
	protected function getLanguageItemValues($languageItem) {
		$sql = 'SELECT language_item.languageItemValue, language_item.languageCustomItemValue, language_item.languageUseCustomValue, language.languageCode
			FROM wcf' . $this->dbNo . '_language_item language_item
			LEFT JOIN wcf' . $this->dbNo . '_language language ON (language.languageID = language_item.languageID)
			WHERE language_item.languageItem = ?';
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array($languageItem));

		$values = array();
		while ($row = $statement->fetchArray()) {
			$values[$row['languageCode']] = ($row['languageUseCustomValue'] ? $row['languageCustomItemValue'] : $row['languageItemValue']);
		}

		return $values;
	}

	/**
	 * Imports language variables.
	 *
	 * @param string $languageCategory
	 * @param string $languageItem
	 * @param array  $languageItemValues
	 * @return array
	 * @throws SystemException
	 * @throws \wcf\system\database\exception\DatabaseQueryException
	 * @throws \wcf\system\database\exception\DatabaseQueryExecutionException
	 */
	protected function importLanguageVariable($languageCategory, $languageItem, array $languageItemValues) {
		$packageID = PackageCache::getInstance()->getPackageID('de.codequake.cms');

		// get language category id
		$sql = 'SELECT languageCategoryID
			FROM wcf' . WCF_N . '_language_category
			WHERE languageCategory = ?';
		$statement = WCF::getDB()->prepareStatement($sql);
		$statement->execute(array($languageCategory));
		$row = $statement->fetchArray();

		$languageCategoryID = $row['languageCategoryID'];

		$importableValues = array();
		foreach ($languageItemValues as $languageCode => $value) {
			$language = LanguageFactory::getInstance()->getLanguageByCode($languageCode);
			if ($language === null) {
				continue;
			}

			$importableValues[$language->languageID] = $value;
		}

		$count = count($importableValues);

		if ($count > 1) {
			$sql = 'INSERT INTO wcf' . WCF_N . '_language_item
				(languageID, languageItem, languageItemValue, languageItemOriginIsSystem, languageCategoryID, packageID)
			        VALUES (?, ?, ?, ?, ?, ?)';
			$statement = WCF::getDB()->prepareStatement($sql);

			foreach ($importableValues as $languageID => $value) {
				$statement->execute(array(
					$languageID,
					$languageItem,
					$value,
					0,
					$languageCategoryID,
					$packageID
				));
			}

			return $languageItem;
		}
		else if ($count === 1) {
			return reset($importableValues);
		}

		return false;
	}

	/**
	 * Updates the i18n data of the category with the given id.
	 *
	 * @param int   $categoryID
	 * @param array $category
	 * @throws SystemException
	 * @throws \wcf\system\database\exception\DatabaseQueryException
	 * @throws \wcf\system\database\exception\DatabaseQueryExecutionException
	 */
	protected function updateCategoryI18nData($categoryID, array $category) {
		// get title
		if (preg_match('~wcf.category.category.title.category\d+~', $category['title'])) {
			$titleValues = $this->getLanguageItemValues($category['title']);
			$title = $this->importLanguageVariable('wcf.category', 'wcf.category.category.title.category' . $categoryID,
				$titleValues);
			if ($title === false) {
				$title = 'Imported Category ' . $categoryID;
			}
		}

		// get description
		if (preg_match('~wcf.category.category.title.category\d+.description~', $category['description'])) {
			$descriptionValues = $this->getLanguageItemValues($category['description']);
			$description = $this->importLanguageVariable('wcf.category',
				'wcf.category.category.description.category' . $categoryID, $descriptionValues);
			if ($description === false) {
				$description = '';
			}
		}

		// update category
		$updateData = array();
		if (!empty($title)) {
			$updateData['title'] = $title;
		}
		if (!empty($description)) {
			$updateData['description'] = $description;
		}

		if (count($updateData)) {
			$importedCategory = new Category(null, array('categoryID' => $categoryID));
			$editor = new CategoryEditor($importedCategory);
			$editor->update($updateData);
		}
	}
}
